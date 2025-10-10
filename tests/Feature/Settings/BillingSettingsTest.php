<?php

use App\Models\BillingInvoice;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Billing\SubscriptionManager;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;

test('billing page lists available plans and invoices', function () {
    $user = User::factory()->create([
        'stripe_id' => 'cus_test123',
    ]);

    $plan = SubscriptionPlan::factory()->create([
        'stripe_price_id' => 'price_basic_test',
        'price' => 1900,
        'currency' => 'USD',
    ]);

    BillingInvoice::factory()->for($user)->for($plan, 'plan')->create([
        'stripe_id' => 'in_test123',
        'total' => 1900,
        'currency' => 'USD',
    ]);

    $response = $this->actingAs($user)->get(route('settings.billing.index'));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('settings/Billing')
        ->has('plans', 1, fn (Assert $planPage) => $planPage
            ->where('id', $plan->id)
            ->where('stripe_price_id', 'price_basic_test')
            ->etc()
        )
        ->has('invoices', 1, fn (Assert $invoice) => $invoice
            ->where('stripe_id', 'in_test123')
            ->etc()
        )
    );
});

test('user can request a subscription setup intent', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('settings.billing.intent'));

    $response->assertOk();
    expect($response->json('client_secret'))->not->toBeNull();
});

test('subscription manager handles subscription actions', function () {
    $user = User::factory()->create();
    $plan = SubscriptionPlan::factory()->create();

    $manager = Mockery::mock(SubscriptionManager::class);
    $manager->shouldReceive('create')
        ->once()
        ->with($user, Mockery::on(fn ($argument) => $argument->is($plan)), 'pm_test', ['coupon' => 'PROMO'])
        ->andReturnNull();
    $manager->shouldReceive('cancel')->once()->with($user);
    $manager->shouldReceive('resume')->once()->with($user);

    $this->app->instance(SubscriptionManager::class, $manager);

    $this->actingAs($user)
        ->post(route('settings.billing.subscribe'), [
            'plan_id' => $plan->id,
            'payment_method' => 'pm_test',
            'coupon' => 'PROMO',
        ])
        ->assertNoContent();

    $this->actingAs($user)
        ->post(route('settings.billing.cancel'))
        ->assertNoContent();

    $this->actingAs($user)
        ->post(route('settings.billing.resume'))
        ->assertNoContent();
});

afterEach(function () {
    Mockery::close();
});
