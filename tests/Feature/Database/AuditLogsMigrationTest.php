<?php

namespace Tests\Feature\Database;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The audit log table on every database CI supports.
 *
 * DDL commits the test's transaction on MySQL, so a test that changes the schema puts it back before it ends.
 */
class AuditLogsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_28_000000_create_audit_logs_table.php');
    }

    #[Test]
    public function the_table_rolls_back_and_forward_cleanly(): void
    {
        $this->assertTrue(Schema::hasColumns('audit_logs', [
            'actor_id', 'actor_label', 'event', 'outcome', 'subject_type', 'subject_id', 'subject_label', 'owner_type', 'owner_id', 'changes', 'meta', 'ip_address', 'user_agent', 'created_at',
        ]));
        $this->assertFalse(Schema::hasColumn('audit_logs', 'updated_at'), 'an entry is never updated');

        $this->migration()->down();
        $this->assertFalse(Schema::hasTable('audit_logs'));

        $this->migration()->up();
        $this->assertTrue(Schema::hasTable('audit_logs'));
    }

    #[Test]
    public function the_log_outlives_the_people_and_records_it_is_about(): void
    {
        $actor = User::factory()->create();
        $entry = AuditLog::factory()->create(['actor_id' => $actor->id, 'actor_label' => 'Gone (gone@example.com)', 'subject_type' => User::class, 'subject_id' => $actor->id, 'subject_label' => 'Gone']);

        // No foreign key: deleting the person leaves what they did, as it was written.
        $actor->delete();

        $this->assertModelExists($entry);
        $this->assertSame($actor->id, $entry->fresh()->actor_id);
        $this->assertSame('Gone (gone@example.com)', $entry->fresh()->actor_label);
    }

    #[Test]
    public function what_changed_and_the_request_are_kept_as_structured_data(): void
    {
        $entry = AuditLog::factory()->create([
            'changes' => [['model' => 'User', 'id' => 1, 'label' => 'Jane', 'action' => 'updated', 'old' => ['nickname' => 'Jane'], 'new' => ['nickname' => 'Janet']]],
            'meta' => ['route' => 'acp.users.update', 'status' => 302],
        ])->fresh();

        $this->assertSame('Janet', $entry->changes[0]['new']['nickname']);
        $this->assertSame(302, $entry->meta['status']);
    }
}
