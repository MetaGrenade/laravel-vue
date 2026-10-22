<?php

namespace Tests\Feature\Billing;

use App\Models\BillingDunningNotice;
use App\Models\BillingWebhookCall;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Notifications\SubscriptionTrialEnding;
use App\Support\Billing\BillingWebhookProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Cashier\Subscription;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The warning a subscriber gets before a free trial becomes a paid subscription (Stripe sends
 * `customer.subscription.trial_will_end` three days before): what happens then, and that they can cancel. Sent once.
 */
class TrialReminderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['stripe_id' => 'cus_rem1']);

        SubscriptionPlan::factory()->create(['name' => 'Pro', 'stripe_price_id' => 'price_rem_pro', 'price' => 2900, 'currency' => 'USD', 'interval' => 'month']);

        $this->subscription = Subscription::create([
            'user_id' => $this->user->id,
            'type' => 'default',
            'stripe_id' => 'sub_rem1',
            'stripe_status' => 'trialing',
            'stripe_price' => 'price_rem_pro',
            'quantity' => 1,
            'trial_ends_at' => now()->addDays(3),
        ]);
    }

    /**
     * @param  array<string, mixed>  $subscription
     * @return array<string, mixed>
     */
    private function event(array $subscription = [], string $eventId = 'evt_rem1'): array
    {
        return [
            'id' => $eventId,
            'object' => 'event',
            'type' => 'customer.subscription.trial_will_end',
            'data' => ['object' => [
                'id' => 'sub_rem1',
                'object' => 'subscription',
                'customer' => 'cus_rem1',
                'status' => 'trialing',
                'trial_end' => now()->addDays(3)->timestamp,
                'cancel_at_period_end' => false,
                'cancel_at' => null,
                ...$subscription,
            ]],
        ];
    }

    private function process(array $payload): void
    {
        app(BillingWebhookProcessor::class)->process($payload);
    }

    #[Test]
    public function the_subscriber_is_told_when_the_trial_ends_what_it_costs_and_that_they_can_cancel(): void
    {
        Notification::fake();

        $this->process($this->event());

        Notification::assertSentToTimes($this->user, SubscriptionTrialEnding::class, 1);
        Notification::assertSentTo($this->user, SubscriptionTrialEnding::class, function (SubscriptionTrialEnding $notification) {
            $mail = $notification->toMail($this->user);
            $text = implode(' ', $mail->introLines).' '.implode(' ', $mail->outroLines);

            $this->assertSame('Your free trial ends soon', $mail->subject);
            $this->assertStringContainsString('free trial of Pro ends on '.now()->addDays(3)->format('F j, Y'), $text);
            $this->assertStringContainsString('29.00 USD per month', $text);
            $this->assertStringContainsString('cancel before then', $text);
            $this->assertSame(route('settings.billing.index'), $mail->actionUrl);

            return true;
        });

        $this->assertDatabaseHas('billing_dunning_notices', ['user_id' => $this->user->id, 'kind' => 'trial_ending', 'reference' => 'sub_rem1', 'attempt' => 0]);
    }

    #[Test]
    public function the_same_event_twice_or_replayed_tells_the_subscriber_once(): void
    {
        Notification::fake();

        $this->process($this->event());
        $this->process($this->event(eventId: 'evt_rem1_again'));
        app(BillingWebhookProcessor::class)->replay(BillingWebhookCall::query()->where('stripe_id', 'evt_rem1')->firstOrFail());

        Notification::assertSentToTimes($this->user, SubscriptionTrialEnding::class, 1);
        $this->assertSame(1, BillingDunningNotice::query()->where('kind', 'trial_ending')->count());
    }

    #[Test]
    public function a_plan_the_shop_does_not_know_still_gets_a_plain_warning(): void
    {
        Notification::fake();
        $this->subscription->forceFill(['stripe_price' => 'price_nobody_sells'])->save();

        $this->process($this->event());

        Notification::assertSentTo($this->user, SubscriptionTrialEnding::class, function (SubscriptionTrialEnding $notification) {
            $text = implode(' ', $notification->toMail($this->user)->introLines);

            $this->assertStringContainsString('Your free trial ends on', $text);
            $this->assertStringNotContainsString(' of ', explode(' ends on', $text)[0]);
            $this->assertStringContainsString('the card you added is charged', $text);

            return true;
        });
    }

    #[Test]
    public function a_trial_that_was_cancelled_is_not_warned_about_however_it_was_cancelled(): void
    {
        Notification::fake();

        // Stripe says so itself.
        $this->process($this->event(['cancel_at_period_end' => true], 'evt_a'));
        $this->process($this->event(['cancel_at' => now()->addDays(3)->timestamp], 'evt_b'));

        // The shop has it as cancelled (Cashier ends a cancelled trial where it would have ended).
        $this->subscription->forceFill(['ends_at' => now()->addDays(3)])->save();
        $this->process($this->event(eventId: 'evt_c'));

        Notification::assertNothingSent();
        $this->assertSame(0, BillingDunningNotice::query()->count());
    }

    #[Test]
    public function a_reminder_for_a_trial_that_has_already_ended_is_not_sent(): void
    {
        Notification::fake();

        $this->process($this->event(['trial_end' => now()->subHour()->timestamp]));

        Notification::assertNothingSent();
    }

    #[Test]
    public function an_event_with_no_end_to_the_trial_is_not_guessed_at(): void
    {
        Notification::fake();

        $this->process($this->event(['trial_end' => null]));

        Notification::assertNothingSent();
    }

    #[Test]
    public function a_subscription_the_shop_does_not_have_tells_nobody_but_the_delivery_is_kept(): void
    {
        Notification::fake();

        $this->process($this->event(['id' => 'sub_unknown']));

        Notification::assertNothingSent();
        $this->assertNotNull(BillingWebhookCall::query()->where('stripe_id', 'evt_rem1')->first());
    }

    #[Test]
    public function a_subscription_whose_user_is_gone_tells_nobody(): void
    {
        Notification::fake();
        $this->user->delete();

        $this->process($this->event());

        Notification::assertNothingSent();
        $this->assertSame(0, BillingDunningNotice::query()->count());
    }

    #[Test]
    public function the_stripe_webhook_endpoint_sends_it(): void
    {
        Notification::fake();
        config(['cashier.webhook.secret' => 'whsec_rem', 'cashier.webhook.cli_secret' => null]);

        $payload = $this->event();
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_rem');

        $this->postJson(route('stripe.webhook'), $payload, ['Stripe-Signature' => $signature])->assertOk();

        Notification::assertSentToTimes($this->user, SubscriptionTrialEnding::class, 1);
    }
}
