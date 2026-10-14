<?php

namespace App\Support\Commerce\Digital;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\LicenceKey;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\Commerce\Catalogue\CatalogueException;
use App\Support\Commerce\OrderLifecycle;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Licence keys: a pool of secrets for a product's stock, one handed to each unit a paid order buys.
 *
 * **The stock count follows the pool.** A stock record that `delivers_keys` holds its count as
 * `unused keys - units that are spoken for` (orders waiting for payment, and paid orders still waiting
 * for a key). So everything that already reads the count (the storefront, the cart, checkout) sells
 * keys without knowing about them, and no order can be placed for a key that is not there. To keep
 * that true, only this class changes the count of a keyed stock record (staff adjustments are
 * refused), and every change is written to the movement ledger.
 *
 * Keys leave the pool when an order is paid ({@see self::issue()}); the unit was already held when
 * the order was placed, so that does not touch the count. A key is the customer's once issued: it is
 * never returned to the pool, even when the order is refunded or staff withdraw it, because the
 * customer may have used it. {@see OrderLifecycle} calls this inside its own transaction.
 */
class LicenceKeys
{
    /** The longest a key may be, in characters. */
    public const MAX_LENGTH = 255;

    /**
     * Start delivering licence keys from a product's stock (or a variant's), creating the stock record
     * if there is none. From then on its count is the number of unused keys, so it starts at zero.
     *
     * @throws CatalogueException
     */
    public function enable(Product $product, ?ProductVariant $variant, ?User $by = null): InventoryItem
    {
        if ($variant !== null && $variant->product_id !== $product->id) {
            throw new CatalogueException('That variant belongs to a different product.');
        }

        return DB::transaction(function () use ($product, $variant, $by) {
            // The same lock the stock screens take, so two requests cannot both create the row.
            Product::query()->whereKey($product->id)->lockForUpdate()->first();

            $item = InventoryItem::query()
                ->where('product_id', $product->id)
                ->where('product_variant_id', $variant?->id)
                ->lockForUpdate()
                ->first();

            if ($item === null) {
                return InventoryItem::create([
                    'product_id' => $product->id,
                    'product_variant_id' => $variant?->id,
                    'quantity' => 0,
                    'allow_backorder' => false,
                    'delivers_keys' => true,
                ]);
            }

            if ($item->delivers_keys) {
                return $item;
            }

            // An order waiting for payment holds some of the current count; resetting it now would
            // let the same units be sold twice.
            if (Order::query()->where('status', OrderStatus::Pending->value)->whereHas('items', fn ($items) => $items->where('product_id', $product->id))->exists()) {
                throw new CatalogueException('Some orders for this product are still waiting for payment. Try again when they have been paid or have expired.');
            }

            $before = $item->quantity;

            $item->forceFill(['delivers_keys' => true, 'allow_backorder' => false, 'quantity' => 0])->save();

            if ($before !== 0) {
                $this->record($item, -$before, 'Stock now follows the licence keys', $by);
            }

            return $item;
        });
    }

    /**
     * Stop delivering keys from this stock, which then counts as ordinary stock again.
     *
     * @throws CatalogueException When keys are still in the pool or paid orders are still owed one.
     */
    public function disable(InventoryItem $item): void
    {
        DB::transaction(function () use ($item) {
            $locked = InventoryItem::query()->lockForUpdate()->findOrFail($item->id);

            if (! $locked->delivers_keys) {
                return;
            }

            $unused = LicenceKey::query()->where('inventory_item_id', $locked->id)->unused()->count();

            if ($unused > 0) {
                throw new CatalogueException("{$unused} unused ".Str::plural('key', $unused).' are still in the pool. Delete them first so none are lost.');
            }

            if ($this->waitingItems($locked)->isNotEmpty()) {
                throw new CatalogueException('Paid orders are still waiting for their keys. Add keys so they can be issued first.');
            }

            $locked->forceFill(['delivers_keys' => false])->save();
        });
    }

    /**
     * Put keys in the pool, one per line. Blank lines are ignored and a key that is already in the pool
     * is skipped. Raises the stock count by the number added.
     *
     * @return array{added: int, duplicates: int}
     *
     * @throws CatalogueException
     */
    public function add(InventoryItem $item, string $text, ?User $by = null): array
    {
        $keys = $this->parse($text);

        return DB::transaction(function () use ($item, $keys, $by) {
            $locked = InventoryItem::query()->lockForUpdate()->findOrFail($item->id);

            if (! $locked->delivers_keys) {
                throw new CatalogueException('Switch on licence keys for this stock first.', 'keys');
            }

            $now = now();
            $added = 0;

            foreach (array_chunk($keys, 200) as $chunk) {
                $added += LicenceKey::query()->insertOrIgnore(array_map(fn (string $key) => [
                    'inventory_item_id' => $locked->id,
                    // Encrypted the way the model's own cast does it, so the model reads it back.
                    'value' => Crypt::encryptString($key),
                    'fingerprint' => LicenceKey::fingerprintOf($key),
                    'added_by' => $by?->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }

            if ($added > 0) {
                InventoryItem::query()->whereKey($locked->id)->increment('quantity', $added);

                $this->record($locked, $added, "{$added} licence ".Str::plural('key', $added).' added', $by);
            }

            return ['added' => $added, 'duplicates' => count($keys) - $added];
        });
    }

    /**
     * Delete keys that are still in the pool. Only keys that nobody holds go, and only as many as the
     * count can spare: units that orders have spoken for stay backed by a key.
     *
     * @param  list<int>  $ids
     * @return int How many were deleted.
     *
     * @throws CatalogueException
     */
    public function remove(InventoryItem $item, array $ids, ?User $by = null): int
    {
        if ($ids === []) {
            return 0;
        }

        return DB::transaction(function () use ($item, $ids, $by) {
            $locked = InventoryItem::query()->lockForUpdate()->findOrFail($item->id);

            $deleted = LicenceKey::query()->where('inventory_item_id', $locked->id)->whereIn('id', $ids)->unused()->delete();

            if ($deleted === 0) {
                return 0;
            }

            // One conditional update, like a sale: the keys that orders are holding units for cannot go.
            $lowered = InventoryItem::query()->whereKey($locked->id)->where('quantity', '>=', $deleted)->decrement('quantity', $deleted);

            if ($lowered === 0) {
                throw new CatalogueException('Orders are waiting for some of these keys, so not that many can be deleted right now.');
            }

            $this->record($locked, -$deleted, "{$deleted} unused licence ".Str::plural('key', $deleted).' deleted', $by);

            return $deleted;
        });
    }

    /**
     * What a stock record's pool looks like.
     *
     * @return array{unused: int, issued: int, withdrawn: int, waiting: int}
     */
    public function counts(InventoryItem $item): array
    {
        $keys = LicenceKey::query()->where('inventory_item_id', $item->id);

        return [
            'unused' => (clone $keys)->unused()->count(),
            'issued' => (clone $keys)->active()->count(),
            'withdrawn' => (clone $keys)->whereNotNull('revoked_at')->count(),
            'waiting' => (int) $this->waitingItems($item)->sum(fn (OrderItem $line) => $this->missing($line)),
        ];
    }

    /**
     * The newest keys still in the pool, masked, for staff to look at.
     *
     * @return list<array{id: int, masked: string, added_at: string|null}>
     */
    public function unused(InventoryItem $item, int $limit = 25): array
    {
        return LicenceKey::query()
            ->where('inventory_item_id', $item->id)
            ->unused()
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (LicenceKey $key) => [
                'id' => $key->id,
                'masked' => $key->masked(),
                'added_at' => $key->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * Give each line that was sold as licence keys the keys it is still missing, taking them from the
     * pool of the stock it was sold from. Safe to call again: a line that has all its keys gets none.
     * Whatever the pool cannot supply is left for later ({@see self::waitingItems()}).
     *
     * Call it with the order locked.
     *
     * @return int How many keys were issued.
     */
    public function issue(Order $order, ?User $by = null): int
    {
        $order->loadMissing('items');

        $issued = 0;

        foreach ($order->items as $item) {
            if (! $item->delivers_keys) {
                continue;
            }

            $missing = $this->missing($item);
            $stock = $missing > 0 ? InventoryItem::resolveFor($item->product_id, $item->product_variant_id) : null;

            if ($stock === null) {
                continue;
            }

            $count = count($this->claim($stock->id, $item->id, $missing));

            if ($count > 0) {
                OrderEvent::record(
                    $order,
                    OrderEvent::LICENCE_ISSUED,
                    "Issued {$count} licence ".Str::plural('key', $count).' for '.($item->description ?: 'an item'),
                    ['order_item_id' => $item->id, 'count' => $count],
                    $by,
                );
            }

            $issued += $count;
        }

        return $issued;
    }

    /**
     * Take a key back from the customer, for a key that leaked or was wrong. The line is owed a key
     * again: with $replace one is taken from the pool at once (refused if there is none to spare);
     * without, the customer waits until a key can be issued.
     *
     * @return LicenceKey|null The new key, when one was issued.
     *
     * @throws CatalogueException
     */
    public function withdraw(LicenceKey $key, ?User $by = null, bool $replace = false): ?LicenceKey
    {
        return DB::transaction(function () use ($key, $by, $replace) {
            $item = $key->order_item_id !== null ? OrderItem::query()->find($key->order_item_id) : null;

            if ($item === null) {
                throw new CatalogueException('That key has not been given to an order.');
            }

            // The order first, like every order change, then the key as it is now.
            $order = Order::query()->lockForUpdate()->findOrFail($item->order_id);
            $locked = LicenceKey::query()->lockForUpdate()->findOrFail($key->id);

            if ($locked->revoked_at !== null) {
                throw new CatalogueException('That key has already been withdrawn.');
            }

            if (! $this->owes($order)) {
                throw new CatalogueException('Only the keys of a paid order that has not been refunded in full can be withdrawn.');
            }

            $stock = InventoryItem::query()->lockForUpdate()->findOrFail($locked->inventory_item_id);

            // One more unit of this stock is now owed to the line. A replacement needs a key to spare;
            // a withdrawal may take the count below zero, like a late payment, and is made good later.
            $held = InventoryItem::query()
                ->whereKey($stock->id)
                ->when($replace, fn ($query) => $query->where('quantity', '>=', 1))
                ->decrement('quantity');

            if ($held === 0) {
                throw new CatalogueException('There is no unused key to replace it with. Add keys first, or withdraw it and let the customer wait.');
            }

            InventoryMovement::create([
                'inventory_item_id' => $stock->id,
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'user_id' => $by?->id,
                'delta' => -1,
                'reason' => InventoryMovement::RESERVATION,
                'note' => $replace ? 'Replacement licence key' : 'Licence key withdrawn',
            ]);

            $locked->forceFill(['revoked_at' => now(), 'revoked_reason' => LicenceKey::BY_STAFF])->save();

            $replacement = null;

            if ($replace) {
                $ids = $this->claim($stock->id, $item->id, 1);

                if ($ids === []) {
                    throw new CatalogueException('There is no unused key to replace it with. Add keys first, or withdraw it and let the customer wait.');
                }

                $replacement = LicenceKey::query()->find($ids[0]);
            }

            OrderEvent::record(
                $order,
                $replace ? OrderEvent::LICENCE_REPLACED : OrderEvent::LICENCE_WITHDRAWN,
                ($replace ? 'Replaced' : 'Withdrew').' a licence key for '.($item->description ?: 'an item'),
                ['order_item_id' => $item->id, 'licence_key_id' => $locked->id],
                $by,
            );

            return $replacement;
        });
    }

    /**
     * Paid orders that are still owed keys from this stock, the one paid longest ago first.
     *
     * @return Collection<int, OrderItem>
     */
    public function waitingItems(InventoryItem $stock): Collection
    {
        return OrderItem::query()
            ->where('delivers_keys', true)
            ->where('product_id', $stock->product_id)
            ->whereHas('order', fn ($order) => $order->whereIn('payment_status', [
                OrderPaymentStatus::Paid->value,
                OrderPaymentStatus::PartiallyRefunded->value,
            ]))
            // Only lines that still owe something: the database does the counting, so the many lines of a popular
            // product that were delivered long ago are never loaded.
            ->whereRaw(
                'order_items.quantity > (select count(*) from licence_keys where licence_keys.order_item_id = order_items.id and (licence_keys.revoked_reason is null or licence_keys.revoked_reason = ?))',
                [LicenceKey::BY_REFUND],
            )
            ->with('order:id,paid_at')
            ->get()
            // A line sold from a variant's own stock, or falling back to the product's, belongs to one record.
            ->filter(fn (OrderItem $line) => InventoryItem::resolveFor($line->product_id, $line->product_variant_id)?->id === $stock->id)
            ->sortBy(fn (OrderItem $line) => [$line->order?->paid_at?->getTimestamp() ?? 0, $line->id])
            ->values();
    }

    /**
     * How many keys a line is still owed: what it bought, less what it was given (not counting keys staff
     * withdrew; a key taken back because of a refund still counts, so a refunded order is never topped up).
     */
    public function missing(OrderItem $item): int
    {
        return max(0, $item->quantity - LicenceKey::query()->where('order_item_id', $item->id)->countedAsIssued()->count());
    }

    /**
     * Whether the order is paid and not refunded in full, so it is owed what it bought.
     */
    public function owes(Order $order): bool
    {
        return in_array($order->payment_status, [OrderPaymentStatus::Paid, OrderPaymentStatus::PartiallyRefunded], true);
    }

    /**
     * What an order's customer sees: the keys they hold, line by line, and how many are still to come.
     * Nothing until the order is paid, and nothing once it is refunded in full.
     *
     * @return list<array{item_id: int, description: string, quantity: int, keys: list<string>, waiting: int}>
     */
    public function forCustomer(Order $order): array
    {
        $order->loadMissing('items');

        $items = $order->items->where('delivers_keys', true);

        if ($items->isEmpty() || ! $this->owes($order)) {
            return [];
        }

        $keys = LicenceKey::query()->active()->whereIn('order_item_id', $items->pluck('id')->all())->orderBy('id')->get()->groupBy('order_item_id');

        return $items->map(function (OrderItem $item) use ($keys) {
            $held = $keys->get($item->id, collect());

            return [
                'item_id' => $item->id,
                'description' => (string) ($item->description ?? 'Item'),
                'quantity' => $item->quantity,
                'keys' => $held->map(fn (LicenceKey $key) => (string) $key->value)->values()->all(),
                'waiting' => max(0, $item->quantity - $held->count()),
            ];
        })->values()->all();
    }

    /**
     * Whether an order that is not paid yet will deliver keys once it is.
     */
    public function willDeliver(Order $order): bool
    {
        $order->loadMissing('items');

        return $order->items->contains(fn (OrderItem $item) => $item->delivers_keys);
    }

    /**
     * What staff see for an order: every key it was given, masked, and what each line is still owed.
     *
     * @return list<array{item_id: int, description: string, quantity: int, waiting: int, keys: list<array{id: int, masked: string, state: string, reason: string|null, issued_at: string|null}>}>
     */
    public function forStaff(Order $order): array
    {
        $order->loadMissing('items');

        $items = $order->items->where('delivers_keys', true);

        if ($items->isEmpty()) {
            return [];
        }

        $keys = LicenceKey::query()->whereIn('order_item_id', $items->pluck('id')->all())->orderBy('id')->get()->groupBy('order_item_id');
        $owed = $this->owes($order);

        return $items->map(function (OrderItem $item) use ($keys, $owed) {
            $held = $keys->get($item->id, collect());

            return [
                'item_id' => $item->id,
                'description' => (string) ($item->description ?? 'Item'),
                'quantity' => $item->quantity,
                // What is still to come: nothing for an order that is not paid or was refunded in full.
                'waiting' => $owed ? max(0, $item->quantity - $held->whereNull('revoked_at')->count()) : 0,
                'keys' => $held->map(fn (LicenceKey $key) => [
                    'id' => $key->id,
                    'masked' => $key->masked(),
                    'state' => $key->revoked_at === null ? 'issued' : 'withdrawn',
                    'reason' => $key->revoked_reason,
                    'issued_at' => $key->issued_at?->toIso8601String(),
                ])->values()->all(),
            ];
        })->values()->all();
    }

    /**
     * Give a line up to $count keys from the pool and return the ids of the ones it got.
     *
     * The unused keys are read with a lock, so a second order claiming at the same moment waits and then
     * sees the first one's keys gone, whatever the database's isolation level; the update still only
     * touches keys nobody holds.
     *
     * @return list<int>
     */
    private function claim(int $inventoryItemId, int $orderItemId, int $count): array
    {
        $ids = LicenceKey::query()
            ->where('inventory_item_id', $inventoryItemId)
            ->unused()
            ->orderBy('id')
            ->limit($count)
            ->lockForUpdate()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($ids === []) {
            return [];
        }

        LicenceKey::query()->whereIn('id', $ids)->unused()->update(['order_item_id' => $orderItemId, 'issued_at' => now()]);

        return LicenceKey::query()->whereIn('id', $ids)->where('order_item_id', $orderItemId)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * The keys in a block of text: one per line, trimmed, blanks and repeats dropped.
     *
     * @return list<string>
     *
     * @throws CatalogueException
     */
    private function parse(string $text): array
    {
        $keys = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $key = trim($line);

            if ($key === '') {
                continue;
            }

            if (mb_strlen($key) > self::MAX_LENGTH) {
                throw new CatalogueException('A licence key can be at most '.self::MAX_LENGTH.' characters. Check that each key is on its own line.', 'keys');
            }

            if (preg_match('/[\x00-\x1F\x7F]/', $key) === 1) {
                throw new CatalogueException('A licence key cannot contain control characters such as tabs.', 'keys');
            }

            $keys[$key] = true;
        }

        $keys = array_map('strval', array_keys($keys));
        $most = max(1, (int) config('commerce.licence_keys.max_per_upload', 1000));

        if ($keys === []) {
            throw new CatalogueException('Enter at least one licence key, one on each line.', 'keys');
        }

        if (count($keys) > $most) {
            throw new CatalogueException("Add at most {$most} keys at a time.", 'keys');
        }

        return $keys;
    }

    private function record(InventoryItem $item, int $delta, string $note, ?User $by): void
    {
        InventoryMovement::create([
            'inventory_item_id' => $item->id,
            'user_id' => $by?->id,
            'delta' => $delta,
            'reason' => InventoryMovement::ADJUSTMENT,
            'note' => $note,
        ]);
    }
}
