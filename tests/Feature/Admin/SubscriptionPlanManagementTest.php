<?php

namespace Tests\Feature\Admin;

use App\Models\SubscriptionPlan;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SubscriptionPlanManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_authorized_staff_can_view_subscription_plans(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('billing.acp.view');

        SubscriptionPlan::factory()->create([
            'name' => 'Starter',
            'stripe_price_id' => 'price_starter',
            'price' => 1200,
            'currency' => 'usd',
            'interval' => 'month',
        ]);

        $response = $this->actingAs($user)->get(route('acp.billing.plans.index'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('acp/BillingPlans')
            ->has('plans', 1, fn (Assert $plan) => $plan
                ->where('name', 'Starter')
                ->where('stripe_price_id', 'price_starter')
                ->etc()
            )
        );
    }

    public function test_staff_can_create_subscription_plan(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('billing.acp.view');

        $response = $this->actingAs($user)->post(route('acp.billing.plans.store'), [
            'name' => 'Pro',
            'slug' => 'pro',
            'stripe_price_id' => 'price_pro',
            'interval' => 'month',
            'price' => 2999,
            'currency' => 'usd',
            'description' => 'Professional tier',
            'features' => ['Feature A', 'Feature B'],
            'is_active' => true,
        ]);

        $response->assertRedirect(route('acp.billing.plans.index'));

        $plan = SubscriptionPlan::where('stripe_price_id', 'price_pro')->first();

        $this->assertNotNull($plan);
        $this->assertSame('Pro', $plan->name);
        $this->assertSame('pro', $plan->slug);
        $this->assertSame('Professional tier', $plan->description);
        $this->assertSame(2999, $plan->price);
        $this->assertSame('USD', $plan->currency);
        $this->assertSame(['Feature A', 'Feature B'], $plan->features);
        $this->assertTrue($plan->is_active);
    }

    public function test_staff_can_update_subscription_plan(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('billing.acp.view');

        $plan = SubscriptionPlan::factory()->create([
            'name' => 'Starter',
            'stripe_price_id' => 'price_starter',
            'price' => 1200,
            'currency' => 'USD',
            'features' => ['Old feature'],
        ]);

        $response = $this->actingAs($user)->put(route('acp.billing.plans.update', $plan), [
            'name' => 'Starter Plus',
            'slug' => $plan->slug,
            'stripe_price_id' => 'price_starter_plus',
            'interval' => 'year',
            'price' => 1500,
            'currency' => 'eur',
            'description' => 'Upgraded starter plan',
            'features' => ['New feature'],
            'is_active' => false,
        ]);

        $response->assertRedirect(route('acp.billing.plans.index'));

        $plan->refresh();

        $this->assertSame('Starter Plus', $plan->name);
        $this->assertSame('price_starter_plus', $plan->stripe_price_id);
        $this->assertSame('year', $plan->interval);
        $this->assertSame(1500, $plan->price);
        $this->assertSame('EUR', $plan->currency);
        $this->assertSame(['New feature'], $plan->features);
        $this->assertFalse($plan->is_active);
        $this->assertSame('Upgraded starter plan', $plan->description);
    }

    public function test_staff_can_delete_subscription_plan(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('billing.acp.view');

        $plan = SubscriptionPlan::factory()->create();

        $response = $this->actingAs($user)->delete(route('acp.billing.plans.destroy', $plan));

        $response->assertRedirect(route('acp.billing.plans.index'));
        $this->assertDatabaseMissing('subscription_plans', ['id' => $plan->id]);
    }

    public function test_permission_is_required_to_manage_subscription_plans(): void
    {
        $user = User::factory()->create();
        $plan = SubscriptionPlan::factory()->create();

        $this->actingAs($user)->get(route('acp.billing.plans.index'))->assertForbidden();
        $this->actingAs($user)->post(route('acp.billing.plans.store'), [
            'name' => 'Starter',
            'slug' => 'starter',
            'stripe_price_id' => 'price_starter',
            'interval' => 'month',
            'price' => 1200,
            'currency' => 'usd',
            'is_active' => true,
        ])->assertForbidden();

        $this->actingAs($user)->put(route('acp.billing.plans.update', $plan), [
            'name' => 'Updated',
            'slug' => $plan->slug,
            'stripe_price_id' => $plan->stripe_price_id,
            'interval' => 'month',
            'price' => 1400,
            'currency' => 'usd',
            'is_active' => true,
        ])->assertForbidden();

        $this->actingAs($user)->delete(route('acp.billing.plans.destroy', $plan))->assertForbidden();
    }
}
