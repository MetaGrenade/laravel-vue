<?php

namespace Tests\Feature\Database;

use App\Models\BillingDunningNotice;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The table of dunning notices on every database CI supports.
 *
 * DDL commits the test's transaction on MySQL, so a test that changes the schema puts it back before it ends. A statement
 * that is expected to fail runs in a savepoint: PostgreSQL abandons the whole transaction after one.
 */
class BillingDunningNoticesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_24_000000_create_billing_dunning_notices_table.php');
    }

    #[Test]
    public function the_table_rolls_back_and_forward_cleanly(): void
    {
        $this->assertTrue(Schema::hasColumns('billing_dunning_notices', ['user_id', 'kind', 'reference', 'attempt']));

        $this->migration()->down();
        $this->assertFalse(Schema::hasTable('billing_dunning_notices'));

        $this->migration()->up();
        $this->assertTrue(Schema::hasTable('billing_dunning_notices'));
    }

    #[Test]
    public function a_notice_is_kept_once_for_each_kind_reference_and_attempt(): void
    {
        $notice = ['kind' => 'failed', 'reference' => 'in_1', 'attempt' => 1];
        BillingDunningNotice::create($notice);

        try {
            DB::transaction(fn () => BillingDunningNotice::create($notice));
            $this->fail('The same notice was kept twice.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        // Another attempt, another kind and another invoice are other notices.
        BillingDunningNotice::create([...$notice, 'attempt' => 2]);
        BillingDunningNotice::create([...$notice, 'kind' => 'recovered', 'attempt' => 0]);
        BillingDunningNotice::create([...$notice, 'reference' => 'in_2']);

        $this->assertSame(4, BillingDunningNotice::query()->count());
    }

    #[Test]
    public function a_notice_outlives_the_user_it_was_for(): void
    {
        $user = User::factory()->create();
        $notice = BillingDunningNotice::create(['user_id' => $user->id, 'kind' => 'failed', 'reference' => 'in_1', 'attempt' => 1]);

        $user->delete();

        $this->assertNull($notice->fresh()->user_id);
    }
}
