<?php

namespace Tests\Feature\Entitlements;

use App\Models\Entitlement;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPlanEntitlement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What the pricing page and the billing settings tell a visitor each plan gives: the entitlements it really switches on or
 * raises, next to the highlights staff wrote about it.
 */
class PlanPagesTest extends TestCase
{
    use RefreshDatabase;

    private function plan(array $gives, array $state = []): SubscriptionPlan
    {
        $plan = SubscriptionPlan::factory()->create($state);

        foreach ($gives as $key => $value) {
            $entitlement = Entitlement::query()->where('key', $key)->first()
                ?? ($value === null ? Entitlement::factory() : Entitlement::factory()->limit())->create(['key' => $key, 'label' => ucfirst($key)]);

            SubscriptionPlanEntitlement::create(['subscription_plan_id' => $plan->id, 'entitlement_id' => $entitlement->id, 'value' => $value]);
        }

        return $plan;
    }

    #[Test]
    public function the_pricing_page_says_what_each_plan_gives_by_name_and_keeps_the_highlights_staff_wrote(): void
    {
        $this->plan(['reports' => null, 'projects' => 25, 'audit' => null], ['name' => 'Pro', 'features' => ['Priority email support']]);
        $this->plan([], ['name' => 'Starter', 'price' => 100, 'features' => []]);

        $this->get(route('pricing'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Pricing')
            ->has('plans', 2)
            ->where('plans.0.name', 'Starter')
            ->where('plans.0.entitlements', [])
            ->where('plans.1.features', ['Priority email support'])
            ->where('plans.1.entitlements', [
                ['key' => 'audit', 'label' => 'Audit', 'type' => 'feature', 'value' => null],
                ['key' => 'projects', 'label' => 'Projects', 'type' => 'limit', 'value' => 25],
                ['key' => 'reports', 'label' => 'Reports', 'type' => 'feature', 'value' => null],
            ]));
    }

    #[Test]
    public function a_plan_switched_off_is_not_offered_with_what_it_gives(): void
    {
        $this->plan(['reports' => null], ['name' => 'Old', 'is_active' => false]);

        $this->get(route('pricing'))->assertInertia(fn (Assert $page) => $page->has('plans', 0));
    }

    #[Test]
    public function the_billing_settings_say_what_each_plan_gives_too(): void
    {
        $plan = $this->plan(['projects' => 10]);

        $this->actingAs(User::factory()->create())->get(route('settings.billing.index'))->assertInertia(fn (Assert $page) => $page
            ->component('settings/Billing')
            ->where('plans.0.id', $plan->id)
            ->where('plans.0.entitlements', [['key' => 'projects', 'label' => 'Projects', 'type' => 'limit', 'value' => 10]]));
    }

    #[Test]
    public function what_plans_give_is_read_in_a_fixed_number_of_queries_however_many_plans_there_are(): void
    {
        foreach (range(1, 4) as $index) {
            $this->plan(['reports' => null, 'projects' => $index], ['name' => "Plan {$index}"]);
        }

        DB::enableQueryLog();
        $this->get(route('pricing'))->assertOk();
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $this->assertCount(1, $queries->filter(fn (string $sql) => str_contains($sql, 'from "subscription_plan_entitlements"') || str_contains($sql, 'from `subscription_plan_entitlements`')));
        $this->assertCount(1, $queries->filter(fn (string $sql) => str_contains($sql, 'from "entitlements"') || str_contains($sql, 'from `entitlements`')));
    }
}
