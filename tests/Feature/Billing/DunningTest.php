<?php

namespace Tests\Feature\Billing;

use App\Models\BillingDunningNotice;
use App\Models\BillingInvoice;
use App\Models\BillingWebhookCall;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Notifications\SubscriptionCancelledForNonPayment;
use App\Notifications\SubscriptionPaymentFailed;
use App\Notifications\SubscriptionPaymentRecovered;
use App\Support\Billing\BillingWebhookProcessor;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Testing\Fakes\NotificationFake;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * What a subscriber is told when a payment for their plan fails, goes through after failing, or ends the subscription: each
 * notice once, however often Stripe delivers the event or an admin replays it.
 */
class DunningTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private SubscriptionPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['stripe_id' => 'cus_dun1']);
        $this->plan = SubscriptionPlan::factory()->create(['name' => 'Pro', 'stripe_price_id' => 'price_dun_pro']);
    }

    protected function tearDown(): void
    {
        Cashier::$deactivatePastDue = true;

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $invoice
     * @return array<string, mixed>
     */
    private function event(string $type, string $eventId, array $invoice): array
    {
        return [
            'id' => $eventId,
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => [
                'id' => 'in_dun1',
                'object' => 'invoice',
                'status' => 'open',
                'amount_due' => 2900,
                'total' => 2900,
                'customer' => 'cus_dun1',
                'currency' => 'usd',
                'lines' => ['data' => [['price' => ['id' => 'price_dun_pro']]]],
                ...$invoice,
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function failed(int $attempt = 1, ?int $next = 1893456000, string $eventId = 'evt_dun_f1', array $extra = []): array
    {
        return $this->event('invoice.payment_failed', $eventId, ['attempt_count' => $attempt, 'next_payment_attempt' => $next, ...$extra]);
    }

    /**
     * @return array<string, mixed>
     */
    private function succeeded(string $eventId = 'evt_dun_s1'): array
    {
        return $this->event('invoice.payment_succeeded', $eventId, ['status' => 'paid', 'status_transitions' => ['paid_at' => 1893456100]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function deleted(string $reason, string $eventId = 'evt_dun_d1'): array
    {
        return [
            'id' => $eventId,
            'type' => 'customer.subscription.deleted',
            'data' => ['object' => [
                'id' => 'sub_dun1',
                'object' => 'subscription',
                'status' => 'canceled',
                'customer' => 'cus_dun1',
                'cancellation_details' => ['reason' => $reason],
            ]],
        ];
    }

    private function process(array $payload): void
    {
        app(BillingWebhookProcessor::class)->process($payload);
    }

    private function subscribe(): Subscription
    {
        return Subscription::create([
            'user_id' => $this->user->id,
            'type' => 'default',
            'stripe_id' => 'sub_dun1',
            'stripe_status' => 'active',
            'stripe_price' => 'price_dun_pro',
            'quantity' => 1,
        ]);
    }

    // --- A payment fails ---------------------------------------------------------------------------------------------------------

    #[Test]
    public function a_failed_payment_tells_the_subscriber_what_happened_and_what_to_do(): void
    {
        Notification::fake();

        $this->process($this->failed());

        Notification::assertSentToTimes($this->user, SubscriptionPaymentFailed::class, 1);
        Notification::assertSentTo($this->user, SubscriptionPaymentFailed::class, function (SubscriptionPaymentFailed $notification) {
            $mail = $notification->toMail($this->user);
            $text = implode(' ', $mail->introLines);

            $this->assertSame("We couldn't take your payment", $mail->subject);
            $this->assertStringContainsString('29.00 USD for Pro', $text);
            $this->assertStringContainsString('paused', $text, 'a failed payment takes the features away, so the email says so');
            $this->assertStringContainsString('January 1, 2030', $text, 'the date Stripe will try again, in words');
            $this->assertSame(route('settings.billing.index'), $mail->actionUrl);

            return $notification->attempt === 1;
        });

        $this->assertDatabaseHas('billing_dunning_notices', ['user_id' => $this->user->id, 'kind' => 'failed', 'reference' => 'in_dun1', 'attempt' => 1]);
    }

    #[Test]
    public function the_same_delivery_twice_or_replayed_tells_the_subscriber_once(): void
    {
        Notification::fake();

        $this->process($this->failed());
        $this->process($this->failed());

        // An admin replaying the stored delivery runs the handler again.
        $call = BillingWebhookCall::query()->where('stripe_id', 'evt_dun_f1')->firstOrFail();
        app(BillingWebhookProcessor::class)->replay($call);

        Notification::assertSentToTimes($this->user, SubscriptionPaymentFailed::class, 1);
        $this->assertSame(1, BillingDunningNotice::query()->count());
    }

    #[Test]
    public function each_new_attempt_is_told_again_with_its_own_wording(): void
    {
        Notification::fake();

        $this->process($this->failed(attempt: 1, eventId: 'evt_a1'));
        $this->process($this->failed(attempt: 2, eventId: 'evt_a2'));
        $this->process($this->failed(attempt: 2, eventId: 'evt_a2_again'));

        Notification::assertSentToTimes($this->user, SubscriptionPaymentFailed::class, 2);

        $subjects = Notification::sent($this->user, SubscriptionPaymentFailed::class)
            ->map(fn (SubscriptionPaymentFailed $notification) => $notification->toMail($this->user)->subject)
            ->all();

        $this->assertSame(["We couldn't take your payment", "We still couldn't take your payment"], $subjects);
    }

    #[Test]
    public function when_stripe_will_not_try_again_the_email_says_so(): void
    {
        Notification::fake();

        $this->process($this->failed(attempt: 4, next: null));

        Notification::assertSentTo($this->user, SubscriptionPaymentFailed::class, function (SubscriptionPaymentFailed $notification) {
            $text = implode(' ', $notification->toMail($this->user)->introLines);

            $this->assertStringContainsString("won't try again automatically", $text);
            $this->assertStringNotContainsString('We will try again on', $text);

            return true;
        });
    }

    #[Test]
    public function the_email_follows_whether_a_late_payment_takes_the_plan_away(): void
    {
        Notification::fake();
        Cashier::keepPastDueSubscriptionsActive();

        $this->process($this->failed());

        Notification::assertSentTo($this->user, SubscriptionPaymentFailed::class, function (SubscriptionPaymentFailed $notification) {
            $text = implode(' ', $notification->toMail($this->user)->introLines);

            $this->assertStringContainsString('stays active', $text);
            $this->assertStringNotContainsString('paused', $text);

            return true;
        });
    }

    #[Test]
    public function the_first_payment_of_a_new_subscription_is_not_chased_by_email(): void
    {
        Notification::fake();

        $this->process($this->failed(extra: ['billing_reason' => 'subscription_create']));

        Notification::assertNothingSent();
        $this->assertSame(0, BillingDunningNotice::query()->count());
    }

    #[Test]
    public function a_payment_for_someone_unknown_tells_nobody(): void
    {
        Notification::fake();

        $this->process($this->failed(extra: ['customer' => 'cus_nobody']));

        Notification::assertNothingSent();
        $this->assertSame(0, BillingDunningNotice::query()->count());
        $this->assertSame(1, BillingInvoice::query()->count(), 'the invoice is still recorded');
    }

    // --- It goes through after failing ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_payment_that_goes_through_after_failing_says_so_once(): void
    {
        Notification::fake();

        $this->process($this->failed());
        $this->process($this->succeeded());
        $this->process($this->succeeded('evt_dun_s1_again'));

        Notification::assertSentToTimes($this->user, SubscriptionPaymentRecovered::class, 1);
        Notification::assertSentTo($this->user, SubscriptionPaymentRecovered::class, function (SubscriptionPaymentRecovered $notification) {
            $mail = $notification->toMail($this->user);

            $this->assertSame('Payment received, thank you', $mail->subject);
            $this->assertStringContainsString('29.00 USD for Pro', implode(' ', $mail->introLines));

            return true;
        });
    }

    #[Test]
    public function an_ordinary_payment_that_never_failed_sends_nothing(): void
    {
        Notification::fake();

        $this->process($this->succeeded());

        Notification::assertNothingSent();
    }

    // --- The subscription ends -------------------------------------------------------------------------------------------------------

    #[Test]
    public function a_subscription_whose_user_is_gone_tells_nobody(): void
    {
        Notification::fake();
        $this->subscribe();

        // Subscriptions point at their user without a foreign key, so one can outlive them.
        $this->user->delete();

        $this->process($this->deleted('payment_failed'));

        Notification::assertNothingSent();
        $this->assertSame(0, BillingDunningNotice::query()->count());
    }

    #[Test]
    public function a_subscription_ended_for_want_of_payment_tells_the_subscriber(): void
    {
        Notification::fake();
        $this->subscribe();

        $this->process($this->deleted('payment_failed'));
        $this->process($this->deleted('payment_failed', 'evt_dun_d1_again'));

        Notification::assertSentToTimes($this->user, SubscriptionCancelledForNonPayment::class, 1);
        Notification::assertSentTo($this->user, SubscriptionCancelledForNonPayment::class, function (SubscriptionCancelledForNonPayment $notification) {
            $mail = $notification->toMail($this->user);

            $this->assertSame('Your subscription has ended', $mail->subject);
            $this->assertStringContainsString('Pro', implode(' ', $mail->introLines));
            $this->assertSame(route('pricing'), $mail->actionUrl);

            return true;
        });
    }

    #[Test]
    public function a_subscription_the_customer_cancelled_is_not_blamed_on_payment(): void
    {
        Notification::fake();
        $this->subscribe();

        $this->process($this->deleted('cancellation_requested'));

        Notification::assertNothingSent();
        $this->assertSame('canceled', Subscription::query()->firstOrFail()->stripe_status);
    }

    #[Test]
    public function a_subscription_we_do_not_have_tells_nobody(): void
    {
        Notification::fake();

        $this->process($this->deleted('payment_failed'));

        Notification::assertNothingSent();
    }

    // --- Sending fails ----------------------------------------------------------------------------------------------------------------

    #[Test]
    public function when_the_email_cannot_be_sent_the_notice_is_not_kept_so_stripes_next_delivery_tries_again(): void
    {
        $this->app->instance(Dispatcher::class, new class implements Dispatcher
        {
            public function send($notifiables, $notification): void
            {
                throw new RuntimeException('the mail queue is down');
            }

            public function sendNow($notifiables, $notification, ?array $channels = null): void
            {
                throw new RuntimeException('the mail queue is down');
            }
        });

        try {
            $this->process($this->failed());
            $this->fail('The failure to send was swallowed.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, BillingDunningNotice::query()->count(), 'nothing is claimed for an email that was not sent');

        // The mail queue is back.
        $fake = new NotificationFake;
        $this->app->instance(Dispatcher::class, $fake);
        $this->process($this->failed());

        $fake->assertSentToTimes($this->user, SubscriptionPaymentFailed::class, 1);
    }

    // --- Through the webhook ----------------------------------------------------------------------------------------------------------

    #[Test]
    public function the_stripe_webhook_endpoint_sends_it(): void
    {
        Notification::fake();
        config(['cashier.webhook.secret' => 'whsec_dun', 'cashier.webhook.cli_secret' => null]);

        $payload = $this->failed();
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_dun');

        $this->postJson(route('stripe.webhook'), $payload, ['Stripe-Signature' => $signature])->assertOk();

        Notification::assertSentToTimes($this->user, SubscriptionPaymentFailed::class, 1);
    }
}
