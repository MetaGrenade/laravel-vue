<?php

namespace Tests\Feature\Webhooks;

use App\Models\BillingInvoice;
use App\Models\BillingWebhookCall;
use App\Models\SubscriptionPlan;
use App\Models\User;
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

    public function test_invoice_payment_failed_webhook_persists_invoice_details(): void
    {
        config(['cashier.webhook.secret' => 'whsec_test']);

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
        config(['cashier.webhook.secret' => 'whsec_test']);

        $user = User::factory()->create(['stripe_id' => 'cus_test123']);

        $subscription = Subscription::create([
            'user_id' => $user->id,
            'name' => config('billing.subscription_name', 'default'),
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
        $this->assertTrue($subscription->cancelled());

        $webhook = BillingWebhookCall::firstWhere('stripe_id', 'evt_test_deleted');
        $this->assertNotNull($webhook);
    }

    public function test_webhook_rejects_requests_with_invalid_signature(): void
    {
        config(['cashier.webhook.secret' => 'whsec_test']);

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
}
