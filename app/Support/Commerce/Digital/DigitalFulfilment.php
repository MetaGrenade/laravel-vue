<?php

namespace App\Support\Commerce\Digital;

use App\Enums\OrderPaymentStatus;
use App\Models\DownloadGrant;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductFile;
use App\Support\Commerce\OrderLifecycle;

/**
 * What a paid order owes in digital goods: the right to download the files of each line that has
 * some, and whether the order is made only of such lines (so there is nothing to ship).
 *
 * {@see OrderLifecycle} calls this when an order is paid and when a refund
 * settles, inside its own transaction, so every method is safe to call again.
 */
class DigitalFulfilment
{
    /**
     * Give each line whose product has files the right to download them. A line has at most one
     * grant, so paying twice (a redelivered webhook) changes nothing. The expiry, if any, runs from now.
     *
     * @return int How many grants were created.
     */
    public function grant(Order $order): int
    {
        $order->loadMissing('items');

        $productIds = $order->items->pluck('product_id')->filter()->unique()->all();

        if ($productIds === []) {
            return 0;
        }

        // A product with any file at all, switched on or not: a file switched on later reaches the buyer.
        $delivering = ProductFile::query()->whereIn('product_id', $productIds)->distinct()->pluck('product_id')->map(fn ($id) => (int) $id)->all();

        $days = (int) config('commerce.downloads.expires_after_days', 0);
        $expires = $days > 0 ? now()->addDays($days) : null;
        $created = 0;

        /** @var OrderItem $item */
        foreach ($order->items as $item) {
            if ($item->product_id === null || ! in_array((int) $item->product_id, $delivering, true)) {
                continue;
            }

            $grant = DownloadGrant::query()->firstOrCreate(
                ['order_item_id' => $item->id],
                ['order_id' => $order->id, 'product_id' => $item->product_id, 'expires_at' => $expires],
            );

            $created += $grant->wasRecentlyCreated ? 1 : 0;
        }

        return $created;
    }

    /**
     * Whether the downloads deliver the whole order: every line was digital when it was bought and has
     * its files granted, so there is nothing left to ship and nothing for a person to send by hand.
     * A line that needs no shipping but has no files (a service, a licence someone emails) is not
     * delivered by this, so the order stays open for whoever does that.
     *
     * Call it after {@see self::grant()}.
     */
    public function isDeliveredInFull(Order $order): bool
    {
        $order->loadMissing('items');

        if ($order->items->isEmpty()) {
            return false;
        }

        $granted = DownloadGrant::query()->where('order_id', $order->id)->pluck('order_item_id')->map(fn ($id) => (int) $id)->all();

        return $order->items->every(fn (OrderItem $item) => ! $item->requires_shipping && in_array((int) $item->id, $granted, true));
    }

    /**
     * Access follows the money: it ends when the order is refunded in full, and comes back if that refund
     * later fails. Only revocations made because of a refund are touched, never one a person made.
     */
    public function syncRevocation(Order $order): void
    {
        $grants = DownloadGrant::query()->where('order_id', $order->id);

        if ($order->payment_status === OrderPaymentStatus::Refunded) {
            $grants->whereNull('revoked_at')->update([
                'revoked_at' => now(),
                'revoked_reason' => DownloadGrant::BY_REFUND,
            ]);

            return;
        }

        $grants->where('revoked_reason', DownloadGrant::BY_REFUND)->update([
            'revoked_at' => null,
            'revoked_reason' => null,
        ]);
    }
}
