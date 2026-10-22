<?php

namespace Tests\Feature\Database;

use App\Models\SubscriptionPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The trial column on every database CI supports.
 *
 * DDL commits the test's transaction on MySQL, so a test that changes the schema puts it back before it ends.
 */
class PlanTrialDaysMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_25_000000_add_trial_days_to_subscription_plans_table.php');
    }

    #[Test]
    public function the_column_rolls_back_and_forward_cleanly(): void
    {
        $this->assertTrue(Schema::hasColumn('subscription_plans', 'trial_days'));

        $this->migration()->down();
        $this->assertFalse(Schema::hasColumn('subscription_plans', 'trial_days'));

        $this->migration()->up();
        $this->assertTrue(Schema::hasColumn('subscription_plans', 'trial_days'));
    }

    #[Test]
    public function plans_have_no_trial_unless_one_is_set(): void
    {
        $this->assertNull(SubscriptionPlan::factory()->create()->fresh()->trial_days);
        $this->assertSame(14, SubscriptionPlan::factory()->create(['trial_days' => 14])->fresh()->trial_days);
    }
}
