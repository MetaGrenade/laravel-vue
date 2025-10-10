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

test('invoice payment failed webhook persists invoice details', function () {
    SubscriptionPlan::factory()->create(['stripe_price_id' => 'price_starter']);
    $user = User::factory()->create(['stripe_id' => 'cus_test123']);

    $payload = stripeFixture('invoice_payment_failed');

    $response = $this->postJson(route('stripe.webhook'), $payload);

    $response->assertOk();

    $invoice = BillingInvoice::firstWhere('stripe_id', 'in_test_failed');

    expect($invoice)->not->toBeNull()
        ->and($invoice->status)->toBe('failed')
        ->and($invoice->user_id)->toBe($user->id);

    $webhook = BillingWebhookCall::firstWhere('stripe_id', 'evt_test_failed');
    expect($webhook)->not->toBeNull();
});

test('subscription deletion webhook cancels subscription', function () {
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

    $response = $this->postJson(route('stripe.webhook'), $payload);

    $response->assertOk();

    $subscription->refresh();

    expect($subscription->stripe_status)->toBe('canceled')
        ->and($subscription->cancelled())->toBeTrue();

    $webhook = BillingWebhookCall::firstWhere('stripe_id', 'evt_test_deleted');
    expect($webhook)->not->toBeNull();
});
