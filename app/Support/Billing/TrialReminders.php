<?php

namespace App\Support\Billing;

use App\Models\BillingDunningNotice;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Notifications\SubscriptionTrialEnding;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Laravel\Cashier\Subscription;

/**
 * Warns a subscriber that their free trial is about to turn into a paid subscription. Stripe says so itself
 * (`customer.subscription.trial_will_end`, three days before the trial ends); this tells the customer what happens then, and
 * that they can cancel before it does. Sent once, however often the event is delivered or replayed.
 */
class TrialReminders
{
    public function __construct(private readonly BillingNotices $notices) {}

    /**
     * @param  array<string, mixed>  $payload  The Stripe event.
     */
    public function trialEnding(Subscription $subscription, array $payload): void
    {
        $stripeSubscription = (array) Arr::get($payload, 'data.object', []);

        // A trial that was cancelled will not become a charge, so there is nothing to warn about.
        if ($subscription->canceled()
            || Arr::get($stripeSubscription, 'cancel_at_period_end') === true
            || Arr::get($stripeSubscription, 'cancel_at') !== null) {
            return;
        }

        $trialEnd = Arr::get($stripeSubscription, 'trial_end');
        $end = is_numeric($trialEnd) ? Carbon::createFromTimestamp((int) $trialEnd) : null;

        // A reminder for a trial that has already ended (a delivery that was held up, or replayed) is no use to anyone, and one
        // for a trial with no end has nothing to say.
        if ($end === null || $end->isPast()) {
            return;
        }

        $user = User::query()->find($subscription->user_id);

        if ($user === null) {
            return;
        }

        $plan = SubscriptionPlan::query()->where('stripe_price_id', $subscription->stripe_price)->first();

        $this->notices->once(
            $user,
            BillingDunningNotice::TRIAL_ENDING,
            $subscription->stripe_id,
            0,
            new SubscriptionTrialEnding($plan?->name, $end, $plan?->price, $plan?->currency, $plan?->interval),
        );
    }
}
