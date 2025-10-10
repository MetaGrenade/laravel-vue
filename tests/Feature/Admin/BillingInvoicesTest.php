<?php

use App\Models\BillingInvoice;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Inertia\Testing\AssertableInertia as Assert;

test('authorized staff can view billing invoices', function () {
    $this->seed(RolePermissionSeeder::class);

    $user = User::factory()->create();
    $user->givePermissionTo('billing.acp.view');

    $plan = SubscriptionPlan::factory()->create(['name' => 'Starter']);
    $invoice = BillingInvoice::factory()->for($user)->for($plan, 'plan')->create([
        'stripe_id' => 'in_test123',
        'total' => 2500,
        'currency' => 'USD',
    ]);

    $response = $this->actingAs($user)->get(route('acp.billing.invoices.index'));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('acp/BillingInvoices')
        ->has('invoices.data', 1, fn (Assert $row) => $row
            ->where('stripe_id', 'in_test123')
            ->etc()
        )
    );
});
