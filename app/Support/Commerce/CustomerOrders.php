<?php

namespace App\Support\Commerce;

use App\Enums\OrderPaymentStatus;
use App\Models\DownloadGrant;
use App\Models\LicenceKey;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Support\Commerce\Digital\LicenceKeys;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What a customer sees of their own orders, on their dashboard and on their order history: each order
 * with its totals and what it delivers (licence keys, downloads), and the digital purchases they can
 * open to find a key or a file again.
 *
 * Only a count of keys is ever sent here, never a key: the keys themselves are shown on the order page
 * (which is never cached), so that is the one place a secret has to be protected. Everything is
 * worked out in a fixed number of queries however many orders there are.
 */
class CustomerOrders
{
    public function __construct(private readonly LicenceKeys $keys) {}

    /**
     * The orders a user may see: whoever owns them, not whoever happened to place them.
     *
     * @return Builder<Order>
     */
    public function for(User $user): Builder
    {
        return Order::query()->visibleTo($user);
    }

    /**
     * Orders as the customer's pages show them, in the order given.
     *
     * @param  iterable<Order>  $orders  With their items loaded.
     * @return list<array<string, mixed>>
     */
    public function present(iterable $orders): array
    {
        $orders = collect($orders)->values();

        if ($orders->isEmpty()) {
            return [];
        }

        $keyedItems = $orders->flatMap(fn (Order $order) => $order->items)->where('delivers_keys', true);

        // Keys each line holds now (not the ones taken back), counted in one query.
        $held = $keyedItems->isEmpty()
            ? collect()
            : LicenceKey::query()
                ->active()
                ->whereIn('order_item_id', $keyedItems->pluck('id')->all())
                ->selectRaw('order_item_id, count(*) as aggregate')
                ->groupBy('order_item_id')
                ->pluck('aggregate', 'order_item_id');

        $grants = DownloadGrant::query()->whereIn('order_id', $orders->pluck('id')->all())->get()->groupBy('order_id');

        return $orders->map(function (Order $order) use ($held, $grants) {
            // Nothing is delivered before the order is paid, or after it was refunded in full.
            $owed = $this->keys->owes($order);
            $keyed = $order->items->where('delivers_keys', true);
            $holding = $owed ? (int) $keyed->sum(fn (OrderItem $item) => (int) ($held[$item->id] ?? 0)) : 0;
            $waiting = $owed ? (int) $keyed->sum(fn (OrderItem $item) => max(0, $item->quantity - (int) ($held[$item->id] ?? 0))) : 0;

            return [
                'id' => $order->id,
                'number' => $order->number,
                'status' => $order->status->value,
                'status_label' => $order->status->label(),
                'payment_status' => $order->payment_status->value,
                'payment_status_label' => $order->payment_status->label(),
                'currency' => $order->currency,
                'grand_total' => $order->grand_total,
                'refunded_total' => $order->refunded_total,
                'created_at' => $order->created_at?->toIso8601String(),
                'url' => route('shop.checkout.complete', ['order' => $order->public_id]),
                'items' => $order->items->map(fn (OrderItem $item) => [
                    'id' => $item->id,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'subtotal' => $item->subtotal,
                ])->values()->all(),
                // What the customer can find on the order page: how many keys they hold, how many are still
                // to come, and whether any file can still be downloaded.
                'digital' => [
                    'keys' => $holding,
                    'keys_waiting' => $waiting,
                    'downloads' => $owed && ($grants->get($order->id)?->contains(fn (DownloadGrant $grant) => $grant->isUsable()) ?? false),
                ],
            ];
        })->all();
    }

    /**
     * Headline numbers for the dashboard.
     *
     * @return array{orders: int, awaiting_payment: int, in_progress: int, digital: int, keys_waiting: int}
     */
    public function summary(User $user): array
    {
        $byStatus = $this->for($user)
            ->select('status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $digital = $this->digital($user);

        return [
            'orders' => (int) $byStatus->sum(),
            'awaiting_payment' => (int) ($byStatus['pending'] ?? 0),
            'in_progress' => (int) ($byStatus['processing'] ?? 0),
            'digital' => (int) (clone $digital)->count(),
            'keys_waiting' => $this->keysWaiting($user),
        ];
    }

    /**
     * The newest digital purchases (a licence key, or files that can still be downloaded) from paid orders,
     * each with a link to the order page where the key or file is found.
     *
     * @return list<array<string, mixed>>
     */
    public function digitalPurchases(User $user, int $limit = 6): array
    {
        return $this->digital($user)
            ->with(['order:id,public_id,number,paid_at,created_at', 'downloadGrant'])
            ->withCount(['licenceKeys as held_count' => fn ($keys) => $keys->active()])
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(function (OrderItem $item) {
                $held = (int) $item->getAttribute('held_count');
                $grant = $item->downloadGrant;
                $downloads = $grant !== null && $grant->isUsable();

                return [
                    'id' => $item->id,
                    'description' => (string) ($item->description ?? 'Item'),
                    'quantity' => $item->quantity,
                    'order_number' => $item->order?->number,
                    'url' => $item->order ? route('shop.checkout.complete', ['order' => $item->order->public_id]) : null,
                    'purchased_at' => ($item->order->paid_at ?? $item->order?->created_at)?->toIso8601String(),
                    'has_keys' => $item->delivers_keys,
                    'keys' => $item->delivers_keys ? $held : 0,
                    'keys_waiting' => $item->delivers_keys ? max(0, $item->quantity - $held) : 0,
                    'downloads' => $downloads,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Lines of paid orders that deliver something the customer may need to open again: a licence key, or a
     * download that has not been withdrawn or expired.
     *
     * @return Builder<OrderItem>
     */
    private function digital(User $user): Builder
    {
        return OrderItem::query()
            ->whereHas('order', fn (Builder $order) => $order
                ->visibleTo($user)
                ->whereIn('payment_status', [OrderPaymentStatus::Paid->value, OrderPaymentStatus::PartiallyRefunded->value]))
            ->where(function (Builder $line) {
                $line->where('delivers_keys', true)
                    ->orWhereHas('downloadGrant', fn (Builder $grant) => $grant
                        ->whereNull('revoked_at')
                        ->where(fn (Builder $expiry) => $expiry->whereNull('expires_at')->orWhere('expires_at', '>', now())));
            });
    }

    /**
     * Licence keys the customer has paid for and not yet received.
     */
    private function keysWaiting(User $user): int
    {
        return (int) OrderItem::query()
            ->where('delivers_keys', true)
            ->whereHas('order', fn (Builder $order) => $order
                ->visibleTo($user)
                ->whereIn('payment_status', [OrderPaymentStatus::Paid->value, OrderPaymentStatus::PartiallyRefunded->value]))
            ->withCount(['licenceKeys as held_count' => fn ($keys) => $keys->active()])
            ->get()
            ->sum(fn (OrderItem $item) => max(0, $item->quantity - (int) $item->getAttribute('held_count')));
    }
}
