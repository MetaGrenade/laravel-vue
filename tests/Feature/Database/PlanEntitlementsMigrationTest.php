<?php

namespace Tests\Feature\Database;

use App\Models\Entitlement;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPlanEntitlement;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The table of what plans give, on every database CI supports.
 *
 * DDL commits the test's transaction on MySQL, so a test that changes the schema puts it back before it ends. A statement
 * that is expected to fail runs in a savepoint: PostgreSQL abandons the whole transaction after one.
 */
class PlanEntitlementsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_23_000000_create_subscription_plan_entitlements_table.php');
    }

    #[Test]
    public function the_table_rolls_back_and_forward_cleanly(): void
    {
        $this->assertTrue(Schema::hasColumns('subscription_plan_entitlements', ['subscription_plan_id', 'entitlement_id', 'value']));

        $this->migration()->down();
        $this->assertFalse(Schema::hasTable('subscription_plan_entitlements'));

        $this->migration()->up();
        $this->assertTrue(Schema::hasTable('subscription_plan_entitlements'));
    }

    #[Test]
    public function a_plan_gives_an_entitlement_once(): void
    {
        $plan = SubscriptionPlan::factory()->create();
        $entitlement = Entitlement::factory()->create();
        SubscriptionPlanEntitlement::create(['subscription_plan_id' => $plan->id, 'entitlement_id' => $entitlement->id]);

        try {
            DB::transaction(fn () => SubscriptionPlanEntitlement::create(['subscription_plan_id' => $plan->id, 'entitlement_id' => $entitlement->id]));
            $this->fail('A plan was set to give the same entitlement twice.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(1, SubscriptionPlanEntitlement::query()->count());
    }
}
