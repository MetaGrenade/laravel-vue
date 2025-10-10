<?php

use App\Models\BillingInvoice;
use App\Models\BillingWebhookCall;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Laravel\Cashier\Subscription;

function stripeFixture(string $name): array
{
    $path = base_path('tests/Fixtures/stripe/'.$name.'.json');

    return json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
}

function stripeSignatureHeader(array $payload, string $secret, ?int $timestamp = null): string
{
    $timestamp ??= time();
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

    return "t={$timestamp},v1={$signature}";
}

test('invoice payment failed webhook persists invoice details', function () {
    config(['cashier.webhook.secret' => 'whsec_test']);

    SubscriptionPlan::factory()->create(['stripe_price_id' => 'price_starter']);
    $user = User::factory()->create(['stripe_id' => 'cus_test123']);

    $payload = stripeFixture('invoice_payment_failed');

    $response = $this->postJson(
        route('stripe.webhook'),
        $payload,
        ['Stripe-Signature' => stripeSignatureHeader($payload, 'whsec_test')]
    );

    $response->assertOk();

    $invoice = BillingInvoice::firstWhere('stripe_id', 'in_test_failed');

    expect($invoice)->not->toBeNull()
        ->and($invoice->status)->toBe('failed')
        ->and($invoice->user_id)->toBe($user->id);

    $webhook = BillingWebhookCall::firstWhere('stripe_id', 'evt_test_failed');
    expect($webhook)->not->toBeNull();
});

test('subscription deletion webhook cancels subscription', function () {
    config(['cashier.webhook.secret' => 'whsec_test']);

    $user = User::factory()->create(['stripe_id' => 'cus_test123']);

    $subscription = Subscription::create([
        'owner_id' => $user->id,
        'owner_type' => User::class,
        'name' => config('billing.subscription_name', 'default'),
        'stripe_id' => 'sub_test123',
        'stripe_status' => 'active',
        'stripe_price' => 'price_starter',
    ]);

    $payload = stripeFixture('customer_subscription_deleted');

    $response = $this->postJson(
        route('stripe.webhook'),
        $payload,
        ['Stripe-Signature' => stripeSignatureHeader($payload, 'whsec_test')]
    );

    $response->assertOk();

    $subscription->refresh();

    expect($subscription->stripe_status)->toBe('canceled')
        ->and($subscription->cancelled())->toBeTrue();

    $webhook = BillingWebhookCall::firstWhere('stripe_id', 'evt_test_deleted');
    expect($webhook)->not->toBeNull();
});

test('webhook rejects requests with invalid signature', function () {
    config(['cashier.webhook.secret' => 'whsec_test']);

    $payload = stripeFixture('invoice_payment_failed');

    $response = $this->postJson(
        route('stripe.webhook'),
        $payload,
        ['Stripe-Signature' => 't=123,v1=invalid']
    );

    $response->assertStatus(400);

    expect(BillingInvoice::count())->toBe(0)
        ->and(BillingWebhookCall::count())->toBe(0);
});
