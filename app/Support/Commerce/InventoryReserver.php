<?php

namespace App\Support\Commerce;

use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\LicenceKey;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;

/**
 * Holds stock for an order while it waits for payment, and gives it back if the
 * order is cancelled. Every change is written to the movement ledger.
 *
 * A product with no inventory row is not tracked and always available. Call
 * these inside the transaction that places or changes the order.
 */
class InventoryReserver
{
    /**
     * Take stock for every line. With $enforce the call fails (and the caller's
     * transaction should roll back) when a tracked line does not have enough.
     *
     * @throws InsufficientStockException
     */
    public function reserve(Order $order, bool $enforce = true): void
    {
        $short = [];

        foreach ($order->items as $item) {
            $inventory = $this->inventoryFor($item);

            if ($inventory === null) {
                continue;
            }

            $query = InventoryItem::query()->whereKey($inventory->id);

            if ($enforce) {
                // One conditional UPDATE, so two buyers racing for the last unit
                // cannot both succeed whatever the database or isolation level.
                $query->where(function ($query) use ($item) {
                    $query->where('quantity', '>=', $item->quantity)
                        ->orWhere('allow_backorder', true);
                });
            }

            if ($query->decrement('quantity', $item->quantity) === 0) {
                $short[] = $item->description ?: 'an item';

                continue;
            }

            InventoryMovement::create([
                'inventory_item_id' => $inventory->id,
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'delta' => -$item->quantity,
                'reason' => InventoryMovement::RESERVATION,
            ]);
        }

        if ($short !== []) {
            throw new InsufficientStockException($short);
        }
    }

    /**
     * Return whatever the order still holds. Safe to call more than once: only
     * the net quantity still reserved is given back.
     */
    public function release(Order $order, ?string $note = null): void
    {
        $this->giveBack($order, InventoryMovement::RELEASE, $note);
    }

    /**
     * Put a paid order's stock back on the shelf because it was refunded in full.
     * Like {@see self::release()}, only what the order still holds is returned, so
     * repeating it (or releasing afterwards) cannot add stock twice.
     */
    public function restock(Order $order, ?string $note = null): void
    {
        $this->giveBack($order, InventoryMovement::RESTOCK, $note);
    }

    /**
     * Take again the stock that {@see self::restock()} returned, because the refund that
     * returned it did not hold and the order is owed to the customer once more. Like a
     * late payment it may leave stock negative: the order is real, so the goods are spoken for.
     *
     * Only stock that was actually restocked is taken, and only once for each restock, so repeating
     * this cannot take it twice.
     */
    public function takeBack(Order $order, ?string $note = null): void
    {
        $groups = InventoryMovement::query()
            ->where('order_id', $order->id)
            ->whereIn('reason', [InventoryMovement::RESERVATION, InventoryMovement::RELEASE, InventoryMovement::RESTOCK])
            ->orderBy('id')
            ->get()
            ->groupBy(fn (InventoryMovement $movement) => $movement->inventory_item_id.':'.$movement->order_item_id);

        foreach ($groups as $movements) {
            // What the line got back is whatever its latest restock returned (less than it reserved when
            // licence keys were handed out, which are never put back).
            /** @var InventoryMovement|null $restock */
            $restock = $movements->where('reason', InventoryMovement::RESTOCK)->sortBy('id')->last();

            if ($restock === null || $restock->delta <= 0) {
                continue;
            }

            // Already taken again: something was reserved after that restock.
            if ($movements->contains(fn (InventoryMovement $movement) => $movement->reason === InventoryMovement::RESERVATION && $movement->id > $restock->id)) {
                continue;
            }

            InventoryItem::query()->whereKey($restock->inventory_item_id)->decrement('quantity', $restock->delta);

            InventoryMovement::create([
                'inventory_item_id' => $restock->inventory_item_id,
                'order_id' => $order->id,
                'order_item_id' => $restock->order_item_id,
                'delta' => -$restock->delta,
                'reason' => InventoryMovement::RESERVATION,
                'note' => $note,
            ]);
        }
    }

    private function giveBack(Order $order, string $reason, ?string $note): void
    {
        $held = InventoryMovement::query()
            ->where('order_id', $order->id)
            ->whereIn('reason', [InventoryMovement::RESERVATION, InventoryMovement::RELEASE, InventoryMovement::RESTOCK])
            ->get()
            ->groupBy(fn (InventoryMovement $movement) => $movement->inventory_item_id.':'.$movement->order_item_id);

        foreach ($held as $movements) {
            /** @var InventoryMovement $first */
            $first = $movements->first();

            $outstanding = -$movements->sum('delta');

            // A refund puts stock back, but not licence keys the customer was given: they may have used
            // them, so they are retired and the pool is not topped up with them.
            if ($reason === InventoryMovement::RESTOCK) {
                $outstanding -= $this->spentKeys($first);
            }

            if ($outstanding <= 0) {
                continue;
            }

            InventoryItem::query()->whereKey($first->inventory_item_id)->update([
                'quantity' => DB::raw('quantity + '.(int) $outstanding),
            ]);

            InventoryMovement::create([
                'inventory_item_id' => $first->inventory_item_id,
                'order_id' => $order->id,
                'order_item_id' => $first->order_item_id,
                'delta' => $outstanding,
                'reason' => $reason,
                'note' => $note,
            ]);
        }
    }

    private function inventoryFor(OrderItem $item): ?InventoryItem
    {
        return InventoryItem::resolveFor($item->product_id, $item->product_variant_id);
    }

    /**
     * How many licence keys were handed to the order line a movement belongs to: units of stock that
     * were delivered and can never be put back.
     */
    private function spentKeys(InventoryMovement $movement): int
    {
        if ($movement->order_item_id === null) {
            return 0;
        }

        return LicenceKey::query()->where('order_item_id', $movement->order_item_id)->count();
    }
}
