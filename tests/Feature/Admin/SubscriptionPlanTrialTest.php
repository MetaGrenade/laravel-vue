<?php

namespace Tests\Feature\Admin;

use App\Models\SubscriptionPlan;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Choosing how many days of free trial a plan gives, in the ACP.
 */
class SubscriptionPlanTrialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function staff(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['billing.acp.view', 'billing.acp.create', 'billing.acp.edit']);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function form(array $extra = []): array
    {
        return [
            'name' => 'Pro',
            'slug' => 'pro',
            'stripe_price_id' => 'price_pro_trial',
            'interval' => 'month',
            'price' => 2900,
            'currency' => 'usd',
            'is_active' => true,
            ...$extra,
        ];
    }

    #[Test]
    public function a_plan_is_made_with_a_trial(): void
    {
        $this->actingAs($this->staff())->post(route('acp.billing.plans.store'), $this->form(['trial_days' => 14]))->assertSessionHasNoErrors();

        $this->assertSame(14, SubscriptionPlan::query()->firstOrFail()->trial_days);
    }

    #[Test]
    public function a_plan_is_made_without_one_when_the_field_is_empty_or_left_out(): void
    {
        $this->actingAs($this->staff())->post(route('acp.billing.plans.store'), $this->form(['trial_days' => '']))->assertSessionHasNoErrors();
        $this->actingAs($this->staff())->post(route('acp.billing.plans.store'), $this->form(['slug' => 'pro-2', 'stripe_price_id' => 'price_pro_2']))->assertSessionHasNoErrors();

        $this->assertSame([null, null], SubscriptionPlan::query()->orderBy('id')->pluck('trial_days')->all());
    }

    #[Test]
    public function a_trial_is_changed_and_taken_away(): void
    {
        $plan = SubscriptionPlan::factory()->create(['trial_days' => 7, 'stripe_price_id' => 'price_pro_trial', 'slug' => 'pro']);
        $update = fn (mixed $days) => $this->actingAs($this->staff())->put(route('acp.billing.plans.update', $plan), $this->form(['trial_days' => $days]))->assertSessionHasNoErrors();

        $update(30);
        $this->assertSame(30, $plan->fresh()->trial_days);

        $update('');
        $this->assertNull($plan->fresh()->trial_days, 'emptying the field takes the trial away');
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function badTrials(): array
    {
        return [
            'none is not a number of days' => [0],
            'a negative number' => [-3],
            'more than Stripe allows' => [731],
            'not a number' => ['two weeks'],
            'part of a day' => [1.5],
        ];
    }

    #[Test]
    #[DataProvider('badTrials')]
    public function a_trial_that_makes_no_sense_is_refused(mixed $days): void
    {
        $this->actingAs($this->staff())->post(route('acp.billing.plans.store'), $this->form(['trial_days' => $days]))->assertSessionHasErrors('trial_days');

        $this->assertSame(0, SubscriptionPlan::query()->count());
    }

    #[Test]
    public function the_longest_trial_stripe_allows_is_accepted(): void
    {
        $this->actingAs($this->staff())->post(route('acp.billing.plans.store'), $this->form(['trial_days' => 730]))->assertSessionHasNoErrors();

        $this->assertSame(730, SubscriptionPlan::query()->firstOrFail()->trial_days);
    }

    #[Test]
    public function the_plans_pages_show_the_trial(): void
    {
        $plan = SubscriptionPlan::factory()->create(['trial_days' => 14]);
        $staff = $this->staff();

        $this->actingAs($staff)->get(route('acp.billing.plans.index'))->assertInertia(fn (Assert $page) => $page->where('plans.0.trial_days', 14));
        $this->actingAs($staff)->get(route('acp.billing.plans.edit', $plan))->assertInertia(fn (Assert $page) => $page->where('plan.trial_days', 14));
    }
}
