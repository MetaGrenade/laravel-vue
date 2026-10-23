<?php

namespace Tests\Feature\Database;

use App\Models\UsageEvent;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The usage ledger on every database CI supports.
 *
 * DDL commits the test's transaction on MySQL, so a test that changes the schema puts it back before it ends. A statement
 * that is expected to fail runs in a savepoint: PostgreSQL abandons the whole transaction after one.
 */
class UsageEventsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_26_000000_create_usage_events_table.php');
    }

    #[Test]
    public function the_table_rolls_back_and_forward_cleanly(): void
    {
        $this->assertTrue(Schema::hasColumns('usage_events', ['user_id', 'meter', 'quantity', 'occurred_at', 'idempotency_key', 'token_id', 'created_at']));

        $this->migration()->down();
        $this->assertFalse(Schema::hasTable('usage_events'));

        $this->migration()->up();
        $this->assertTrue(Schema::hasTable('usage_events'));
    }

    #[Test]
    public function the_same_key_for_the_same_account_and_meter_is_refused_by_the_database(): void
    {
        $user = User::factory()->create();
        $event = ['user_id' => $user->id, 'meter' => 'api-calls', 'quantity' => 1, 'occurred_at' => now(), 'idempotency_key' => 'k1'];
        UsageEvent::create($event);

        try {
            DB::transaction(fn () => UsageEvent::create($event));
            $this->fail('The same use was kept twice.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        // No key at all is never a duplicate.
        UsageEvent::create([...$event, 'idempotency_key' => null]);
        UsageEvent::create([...$event, 'idempotency_key' => null]);

        $this->assertSame(3, UsageEvent::query()->count());
    }

    #[Test]
    public function a_use_has_the_time_it_was_made_and_when_it_was_written(): void
    {
        $user = User::factory()->create();
        $event = UsageEvent::create(['user_id' => $user->id, 'meter' => 'api-calls', 'occurred_at' => '2026-10-01 09:30:00'])->fresh();

        $this->assertSame(1, $event->quantity, 'one, unless said otherwise');
        $this->assertSame('2026-10-01 09:30:00', $event->occurred_at->toDateTimeString());
        $this->assertNotNull($event->created_at);
    }
}
