<?php

namespace App\Support\Commerce;

use App\Enums\SubscriptionStatus;
use App\Models\ProviderSubscription;

/**
 * Recurring payments kept at a provider, as the pages show them: the customer's own (on their orders page and the page
 * of the order that started it) and staff's (on the order, with what the provider said).
 *
 * Whether it is still running is worked out once here, in the words the pages use: a recurring payment whose cancellation
 * was asked for is not over, it runs to the end of what was paid for.
 */
class ProviderSubscriptions
{
    /**
     * @param  iterable<ProviderSubscription>  $subscriptions  With their order and package loaded.
     * @return list<array<string, mixed>>
     */
    public function present(iterable $subscriptions, bool $forStaff = false): array
    {
        $rows = [];

        foreach ($subscriptions as $subscription) {
            [$state, $label] = $this->state($subscription);
            $live = $subscription->isLive();
            $package = $subscription->package;

            $rows[] = [
                'id' => $subscription->id,
                'package' => $subscription->package_name ?? $package->name ?? 'Recurring package',
                // Where the package is sold, if it still is.
                'package_url' => $package !== null && $package->removed_at === null && $package->ident !== null ? route('shop.packages.show', $package->tebex_id) : null,
                'state' => $state,
                'state_label' => $label,
                'live' => $live,
                'amount' => $subscription->amount,
                'currency' => $subscription->currency,
                'renewals' => $subscription->renewals_count,
                'started_at' => $subscription->started_at?->toIso8601String(),
                'last_payment_at' => $subscription->last_payment_at?->toIso8601String(),
                // Nothing is coming once it is over.
                'next_payment_at' => $live ? $subscription->next_payment_at?->toIso8601String() : null,
                'cancellation_requested_at' => $subscription->cancellation_requested_at?->toIso8601String(),
                'ended_at' => $subscription->ended_at?->toIso8601String(),
                'order_number' => $subscription->order?->number,
                'order_url' => $subscription->order !== null ? route('shop.checkout.complete', ['order' => $subscription->order->public_id]) : null,
                ...($forStaff ? [
                    'reference' => $subscription->reference,
                    'provider' => $subscription->provider,
                    'provider_status' => $subscription->provider_status,
                    'fail_count' => $subscription->fail_count,
                    'cancel_reason' => $subscription->cancel_reason,
                ] : []),
            ];
        }

        return $rows;
    }

    /**
     * @return array{0: string, 1: string} A key for the page to colour by, and the words.
     */
    private function state(ProviderSubscription $subscription): array
    {
        return match (true) {
            $subscription->ended_at !== null || ! $subscription->status->isLive() => ['ended', match ($subscription->status) {
                SubscriptionStatus::Cancelled => 'Cancelled',
                SubscriptionStatus::Expired => 'Expired',
                default => 'Ended',
            }],
            $subscription->cancellation_requested_at !== null => ['cancelling', 'Ends at the end of the period'],
            $subscription->status === SubscriptionStatus::Overdue => ['overdue', 'Payment overdue'],
            default => ['active', 'Active'],
        };
    }
}
