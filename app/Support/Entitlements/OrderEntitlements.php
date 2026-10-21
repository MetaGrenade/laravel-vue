<?php

namespace App\Support\Entitlements;

use App\Enums\OrderPaymentStatus;
use App\Models\Order;
use App\Models\ProviderSubscription;
use App\Models\UserEntitlement;
use App\Support\Commerce\GuestCarryOver;
use App\Support\Commerce\OrderLifecycle;
use App\Support\Commerce\OrderRefunder;

/**
 * What a paid order gives its owner, and when that is taken back.
 *
 * - **{@see self::grant()}** runs when the order is paid ({@see OrderLifecycle}). Each line whose Tebex package has
 *   entitlements gives them, once per line and entitlement, so paying twice (a redelivered webhook) changes nothing. What a
 *   grant is worth is fixed then (a limit's value times the quantity), so changing a package afterwards does not reach
 *   what was already bought.
 * - **{@see self::syncRevocation()}** keeps the grants following the money and the recurring payment, in the same way
 *   downloads do: a grant is taken back when the order is refunded in full (a lost dispute is a refund) or when the
 *   recurring payment it came with has ended, and given back if the refund fails or the recurring payment is live again.
 *   It is called when the order is paid, when a refund settles ({@see OrderRefunder}) and when Tebex says something
 *   about the recurring payment, and is safe to call again.
 *
 * A guest's order has no owner, so its grants have none either, and are of no use to anyone until the order is claimed
 * ({@see GuestCarryOver}), which hands them the order's owner like its payments.
 */
class OrderEntitlements
{
    /**
     * Give each line of a paid order what its package gives.
     *
     * @return int How many grants were created.
     */
    public function grant(Order $order): int
    {
        $order->loadMissing('items.tebexPackage.entitlementLinks.entitlement');

        $created = 0;

        foreach ($order->items as $item) {
            $package = $item->tebexPackage;

            if ($package === null) {
                continue;
            }

            foreach ($package->entitlementLinks as $link) {
                $entitlement = $link->entitlement;

                $exists = UserEntitlement::query()
                    ->where('order_item_id', $item->id)
                    ->where('entitlement_id', $entitlement->id)
                    ->exists();

                if ($exists) {
                    continue;
                }

                (new UserEntitlement)->forceFill([
                    'owner_type' => $order->owner_type,
                    'owner_id' => $order->owner_id,
                    'entitlement_id' => $entitlement->id,
                    'source' => $order->payment_provider ?? 'order',
                    'order_id' => $order->id,
                    'order_item_id' => $item->id,
                    'value' => $entitlement->isLimit() ? (int) $link->value * $item->quantity : null,
                ])->save();

                $created++;
            }
        }

        return $created;
    }

    /**
     * Take back what the order no longer stands behind, and give back what it stands behind again. Only grants taken back
     * for these reasons are touched, never one a person took back.
     */
    public function syncRevocation(Order $order): void
    {
        $grants = UserEntitlement::query()->where('order_id', $order->id)->with('orderItem.tebexPackage')->get();

        if ($grants->isEmpty()) {
            return;
        }

        $refunded = $order->payment_status === OrderPaymentStatus::Refunded;

        // The packages whose recurring payment this order started and which Tebex has ended.
        $ended = ProviderSubscription::query()
            ->where('order_id', $order->id)
            ->whereNotNull('tebex_package_id')
            ->get()
            ->reject(fn (ProviderSubscription $subscription) => $subscription->isLive())
            ->pluck('tebex_package_id')
            ->all();

        $managed = [UserEntitlement::BY_REFUND, UserEntitlement::BY_SUBSCRIPTION];

        foreach ($grants as $grant) {
            $package = $grant->orderItem?->tebexPackage;

            $reason = match (true) {
                $refunded => UserEntitlement::BY_REFUND,
                $package !== null && $package->isSubscription() && in_array($package->id, $ended, true) => UserEntitlement::BY_SUBSCRIPTION,
                default => null,
            };

            if ($reason === null) {
                if ($grant->revoked_at !== null && in_array($grant->revoked_reason, $managed, true)) {
                    $grant->forceFill(['revoked_at' => null, 'revoked_reason' => null])->save();
                }
            } elseif ($grant->revoked_at === null) {
                $grant->forceFill(['revoked_at' => now(), 'revoked_reason' => $reason])->save();
            } elseif ($grant->revoked_reason !== $reason && in_array($grant->revoked_reason, $managed, true)) {
                // Still taken back, but for a different reason now (the refund failed, and the recurring payment had ended).
                $grant->forceFill(['revoked_reason' => $reason])->save();
            }
        }
    }

    /**
     * The order has an owner now: its grants belong to them.
     */
    public function claim(Order $order): void
    {
        UserEntitlement::query()->where('order_id', $order->id)->update([
            'owner_type' => $order->owner_type,
            'owner_id' => $order->owner_id,
        ]);
    }
}
