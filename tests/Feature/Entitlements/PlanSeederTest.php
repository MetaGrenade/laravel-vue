<?php

namespace Tests\Feature\Entitlements;

use App\Models\Entitlement;
use App\Models\SubscriptionPlan;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The example plans come with the example entitlements they give, so a fresh install shows a plan changing what an account
 * can do. Seeding again adds and updates but never takes anything staff did away.
 */
class PlanSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.entitlements' => [
                'projects' => ['label' => 'Projects', 'type' => 'limit', 'description' => 'How many.'],
                'priority-support' => ['label' => 'Priority support', 'type' => 'feature'],
            ],
            'billing.plans' => [
                ['name' => 'Starter', 'slug' => 'starter', 'stripe_price' => 'price_starter', 'price' => 1200, 'entitlements' => ['projects' => 3]],
                ['name' => 'Pro', 'slug' => 'pro', 'stripe_price' => 'price_pro', 'price' => 2900, 'entitlements' => ['projects' => 25, 'priority-support' => true, 'not-defined' => true]],
            ],
        ]);
    }

    /**
     * @return array<string, array<string, int|null>>
     */
    private function given(): array
    {
        return SubscriptionPlan::query()->with('entitlementLinks.entitlement')->get()
            ->mapWithKeys(fn (SubscriptionPlan $plan) => [
                $plan->slug => $plan->entitlementLinks->mapWithKeys(fn ($link) => [$link->entitlement->key => $link->value])->all(),
            ])
            ->all();
    }

    #[Test]
    public function seeding_makes_the_entitlements_and_gives_them_to_the_plans(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);

        $this->assertEqualsCanonicalizing(['projects', 'priority-support'], Entitlement::query()->pluck('key')->all());
        $this->assertSame('limit', Entitlement::query()->where('key', 'projects')->firstOrFail()->type->value);
        $this->assertEquals(['starter' => ['projects' => 3], 'pro' => ['projects' => 25, 'priority-support' => null]], $this->given());
    }

    #[Test]
    public function a_trial_in_the_config_is_seeded_and_a_trial_staff_set_is_not_reset_by_seeding_again(): void
    {
        config(['billing.plans.1.trial_days' => 14]);

        $this->seed(SubscriptionPlanSeeder::class);

        $this->assertNull(SubscriptionPlan::query()->where('slug', 'starter')->firstOrFail()->trial_days, 'a plan whose config says nothing has no trial');
        $this->assertSame(14, SubscriptionPlan::query()->where('slug', 'pro')->firstOrFail()->trial_days);

        // Staff give Starter a trial in the ACP; seeding again, with the config still silent about it, leaves it be.
        SubscriptionPlan::query()->where('slug', 'starter')->update(['trial_days' => 30]);
        $this->seed(SubscriptionPlanSeeder::class);

        $this->assertSame(30, SubscriptionPlan::query()->where('slug', 'starter')->firstOrFail()->trial_days);
    }

    #[Test]
    public function seeding_again_repeats_nothing_and_leaves_what_staff_changed(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);

        Entitlement::query()->where('key', 'projects')->update(['label' => 'Workspaces']);
        $pro = SubscriptionPlan::query()->where('slug', 'pro')->firstOrFail();
        $extra = Entitlement::factory()->create(['key' => 'reports']);
        $pro->entitlementLinks()->create(['entitlement_id' => $extra->id]);

        $this->seed(SubscriptionPlanSeeder::class);

        $this->assertSame(2, Entitlement::query()->whereIn('key', ['projects', 'priority-support'])->count());
        $this->assertSame('Workspaces', Entitlement::query()->where('key', 'projects')->firstOrFail()->label, 'what staff renamed is not renamed back');
        $this->assertEquals(['projects' => 25, 'priority-support' => null, 'reports' => null], $this->given()['pro'], 'what staff added to a plan stays');
        $this->assertSame(3, $pro->entitlementLinks()->count(), 'nothing is given twice');
    }
}
