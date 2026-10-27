<?php

namespace Tests\Feature\Webhooks;

use App\Models\BillingInvoice;
use App\Models\BillingWebhookCall;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Billing\BillingWebhookProcessor;
use App\Support\Billing\Dunning;
use App\Support\Billing\TrialReminders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Laravel\Cashier\Subscription;
use Tests\TestCase;

class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function stripeFixture(string $name): array
    {
        $path = base_path('tests/Fixtures/stripe/'.$name.'.json');

        return json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
    }

    private function stripeSignatureHeader(array $payload, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        return "t={$timestamp},v1={$signature}";
    }

    public function test_invoice_payment_succeeded_webhook_persists_invoice_details(): void
    {
        config([
            'cashier.webhook.secret' => 'whsec_test',
            'cashier.webhook.cli_secret' => null,
        ]);

        SubscriptionPlan::factory()->create(['stripe_price_id' => 'price_starter']);
        $user = User::factory()->create(['stripe_id' => 'cus_test123']);

        $payload = $this->stripeFixture('invoice_payment_succeeded');

        $response = $this->postJson(
            route('stripe.webhook'),
            $payload,
            ['Stripe-Signature' => $this->stripeSignatureHeader($payload, 'whsec_test')]
        );

        $response->assertOk();

        $invoice = BillingInvoice::firstWhere('stripe_id', 'in_test_paid');

        $this->assertNotNull($invoice);
        $this->assertSame('paid', $invoice->status);
        $this->assertSame($user->id, $invoice->user_id);
        $this->assertSame(1737072000, $invoice->paid_at?->timestamp);

        $webhook = BillingWebhookCall::firstWhere('stripe_id', 'evt_test_succeeded');
        $this->assertNotNull($webhook);

        // Subscription events are stored under the same (provider, external id) key as shop events.
        $this->assertSame('stripe', $webhook->provider);
        $this->assertSame('evt_test_succeeded', $webhook->external_id);
    }

    public function test_invoice_payment_failed_webhook_persists_invoice_details(): void
    {
        config([
            'cashier.webhook.secret' => 'whsec_test',
            'cashier.webhook.cli_secret' => null,
        ]);

        SubscriptionPlan::factory()->create(['stripe_price_id' => 'price_starter']);
        $user = User::factory()->create(['stripe_id' => 'cus_test123']);

        $payload = $this->stripeFixture('invoice_payment_failed');

        $response = $this->postJson(
            route('stripe.webhook'),
            $payload,
            ['Stripe-Signature' => $this->stripeSignatureHeader($payload, 'whsec_test')]
        );

        $response->assertOk();

        $invoice = BillingInvoice::firstWhere('stripe_id', 'in_test_failed');

        $this->assertNotNull($invoice);
        $this->assertSame('failed', $invoice->status);
        $this->assertSame($user->id, $invoice->user_id);

        $webhook = BillingWebhookCall::firstWhere('stripe_id', 'evt_test_failed');
        $this->assertNotNull($webhook);
    }

    public function test_subscription_deletion_webhook_cancels_subscription(): void
    {
        config([
            'cashier.webhook.secret' => 'whsec_test',
            'cashier.webhook.cli_secret' => null,
        ]);

        $user = User::factory()->create(['stripe_id' => 'cus_test123']);

        $subscription = Subscription::create([
            'user_id' => $user->id,
            'type' => config('billing.subscription_name', 'default'),
            'stripe_id' => 'sub_test123',
            'stripe_status' => 'active',
            'stripe_price' => 'price_starter',
        ]);

        $payload = $this->stripeFixture('customer_subscription_deleted');

        $response = $this->postJson(
            route('stripe.webhook'),
            $payload,
            ['Stripe-Signature' => $this->stripeSignatureHeader($payload, 'whsec_test')]
        );

        $response->assertOk();

        $subscription->refresh();

        $this->assertSame('canceled', $subscription->stripe_status);
        $this->assertTrue($subscription->canceled());

        $webhook = BillingWebhookCall::firstWhere('stripe_id', 'evt_test_deleted');
        $this->assertNotNull($webhook);
    }

    public function test_a_redelivered_subscription_event_is_not_applied_again(): void
    {
        config([
            'cashier.webhook.secret' => 'whsec_test',
            'cashier.webhook.cli_secret' => null,
        ]);

        $user = User::factory()->create(['stripe_id' => 'cus_test123']);

        $subscription = Subscription::create([
            'user_id' => $user->id,
            'type' => config('billing.subscription_name', 'default'),
            'stripe_id' => 'sub_test123',
            'stripe_status' => 'active',
            'stripe_price' => 'price_starter',
        ]);

        $payload = $this->stripeFixture('customer_subscription_deleted');

        $this->postJson(
            route('stripe.webhook'),
            $payload,
            ['Stripe-Signature' => $this->stripeSignatureHeader($payload, 'whsec_test')]
        )->assertOk();

        $subscription->forceFill(['ends_at' => '2000-01-01 00:00:00'])->save();

        $this->postJson(
            route('stripe.webhook'),
            $payload,
            ['Stripe-Signature' => $this->stripeSignatureHeader($payload, 'whsec_test')]
        )->assertOk()->assertSee('already handled');

        $subscription->refresh();

        $this->assertSame('2000-01-01 00:00:00', $subscription->ends_at?->toDateTimeString());
        $this->assertSame(1, BillingWebhookCall::query()->count());
    }

    public function test_a_delivery_that_overlaps_the_first_does_not_apply_the_event_again(): void
    {
        config([
            'cashier.webhook.secret' => 'whsec_test',
            'cashier.webhook.cli_secret' => null,
        ]);

        $user = User::factory()->create(['stripe_id' => 'cus_test123']);

        $subscription = Subscription::create([
            'user_id' => $user->id,
            'type' => config('billing.subscription_name', 'default'),
            'stripe_id' => 'sub_test123',
            'stripe_status' => 'active',
            'stripe_price' => 'price_starter',
        ]);

        $payload = $this->stripeFixture('customer_subscription_deleted');
        $header = $this->stripeSignatureHeader($payload, 'whsec_test');

        $state = new \stdClass;
        $state->runs = 0;
        $state->claimed = false;
        $state->overlap = function () use ($payload, $header): void {
            $this->postJson(route('stripe.webhook'), $payload, ['Stripe-Signature' => $header])
                ->assertOk()
                ->assertSee('already handled');
        };

        $this->app->singleton(BillingWebhookProcessor::class, function ($app) use ($state) {
            return new class($app->make(Dunning::class), $app->make(TrialReminders::class), $state) extends BillingWebhookProcessor
            {
                public function __construct(
                    Dunning $dunning,
                    TrialReminders $trials,
                    private readonly \stdClass $state,
                ) {
                    parent::__construct($dunning, $trials);
                }

                public function handleCustomerSubscriptionDeleted(array $payload, ?int $fallbackUserId = null): void
                {
                    $this->state->runs++;

                    // The row is claimed before this runs, and it is not marked done until the handler returns.
                    $call = BillingWebhookCall::query()->where('external_id', $payload['id'])->first();
                    $this->state->claimed = $call !== null && $call->processed_at === null;

                    if ($this->state->runs === 1) {
                        ($this->state->overlap)();
                    }

                    parent::handleCustomerSubscriptionDeleted($payload, $fallbackUserId);
                }
            };
        });

        $this->postJson(route('stripe.webhook'), $payload, ['Stripe-Signature' => $header])->assertOk();

        $this->assertSame(1, $state->runs);
        $this->assertTrue($state->claimed);
        $this->assertSame('canceled', $subscription->fresh()->stripe_status);
        $this->assertSame(1, BillingWebhookCall::query()->count());
        $this->assertNotNull(BillingWebhookCall::query()->sole()->processed_at);
    }

    public function test_a_signature_outside_the_window_is_rejected_and_changes_nothing(): void
    {
        config([
            'cashier.webhook.secret' => 'whsec_test',
            'cashier.webhook.cli_secret' => null,
            'cashier.webhook.tolerance' => 300,
        ]);

        SubscriptionPlan::factory()->create(['stripe_price_id' => 'price_starter']);
        User::factory()->create(['stripe_id' => 'cus_test123']);

        $payload = $this->stripeFixture('invoice_payment_succeeded');

        foreach ([time() - 3600, time() + 3600] as $timestamp) {
            $this->postJson(
                route('stripe.webhook'),
                $payload,
                ['Stripe-Signature' => $this->stripeSignatureHeader($payload, 'whsec_test', $timestamp)]
            )->assertStatus(400);
        }

        $this->assertSame(0, BillingInvoice::query()->count());
        $this->assertSame(0, BillingWebhookCall::query()->count());
    }

    public function test_webhook_rejects_requests_with_invalid_signature(): void
    {
        config([
            'cashier.webhook.secret' => 'whsec_test',
            'cashier.webhook.cli_secret' => null,
        ]);

        $payload = $this->stripeFixture('invoice_payment_failed');

        $response = $this->postJson(
            route('stripe.webhook'),
            $payload,
            ['Stripe-Signature' => 't=123,v1=invalid']
        );

        $response->assertStatus(400);

        $this->assertSame(0, BillingInvoice::count());
        $this->assertSame(0, BillingWebhookCall::count());
    }

    public function test_webhook_accepts_cli_secret_when_primary_secret_missing(): void
    {
        config([
            'cashier.webhook.secret' => null,
            'cashier.webhook.cli_secret' => 'whsec_cli',
        ]);

        SubscriptionPlan::factory()->create(['stripe_price_id' => 'price_starter']);
        $user = User::factory()->create(['stripe_id' => 'cus_test123']);

        $payload = $this->stripeFixture('invoice_payment_failed');

        $response = $this->postJson(
            route('stripe.webhook'),
            $payload,
            ['Stripe-Signature' => $this->stripeSignatureHeader($payload, 'whsec_cli')]
        );

        $response->assertOk();

        $invoice = BillingInvoice::firstWhere('stripe_id', 'in_test_failed');

        $this->assertNotNull($invoice);
        $this->assertSame('failed', $invoice->status);
        $this->assertSame($user->id, $invoice->user_id);

        $webhook = BillingWebhookCall::firstWhere('stripe_id', 'evt_test_failed');
        $this->assertNotNull($webhook);
    }

    public function test_webhook_returns_server_error_when_no_secret_configured(): void
    {
        config([
            'cashier.webhook.secret' => null,
            'cashier.webhook.cli_secret' => null,
        ]);

        $payload = $this->stripeFixture('invoice_payment_failed');

        $response = $this->postJson(
            route('stripe.webhook'),
            $payload,
            ['Stripe-Signature' => 't=123,v1=invalid']
        );

        $response->assertStatus(500);

        $this->assertSame(0, BillingInvoice::count());
        $this->assertSame(0, BillingWebhookCall::count());
    }
}
