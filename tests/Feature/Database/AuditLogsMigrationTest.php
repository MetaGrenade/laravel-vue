<?php

namespace Tests\Feature\Database;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
            'actor_id', 'actor_label', 'event', 'outcome', 'subject_type', 'subject_id', 'subject_label', 'changes', 'meta', 'ip_address', 'user_agent', 'created_at',
        ]));
        $this->assertFalse(Schema::hasColumn('audit_logs', 'updated_at'), 'an entry is never updated');

        try {
            // In the order they ran, newest first: the accounts point at the entries, so they go before them (MySQL refuses the other way).
            $this->accountsMigration()->down();
            $this->migration()->down();
            $this->assertFalse(Schema::hasTable('audit_logs'));

            $this->migration()->up();
            $this->accountsMigration()->up();
            $this->assertTrue(Schema::hasTable('audit_logs'));
            $this->assertTrue(Schema::hasTable('audit_log_accounts'));
        } finally {
            if (! Schema::hasTable('audit_logs')) {
                $this->migration()->up();
            }

            if (! Schema::hasTable('audit_log_accounts')) {
                $this->accountsMigration()->up();
            }
        }
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

    private function accountsMigration(): object
    {
        return require database_path('migrations/2026_10_29_000000_create_audit_log_accounts_table.php');
    }

    #[Test]
    public function an_entry_has_no_single_owner_any_more_but_the_accounts_it_concerns(): void
    {
        $this->assertFalse(Schema::hasColumn('audit_logs', 'owner_type'));
        $this->assertFalse(Schema::hasColumn('audit_logs', 'owner_id'));
        $this->assertTrue(Schema::hasColumns('audit_log_accounts', ['audit_log_id', 'owner_type', 'owner_id']));
    }

    #[Test]
    public function the_accounts_table_rolls_back_and_forward_cleanly(): void
    {
        $this->accountsMigration()->down();

        $this->assertFalse(Schema::hasTable('audit_log_accounts'));
        $this->assertTrue(Schema::hasColumns('audit_logs', ['owner_type', 'owner_id']), 'back to one owner');

        $this->accountsMigration()->up();

        $this->assertTrue(Schema::hasTable('audit_log_accounts'));
        $this->assertFalse(Schema::hasColumn('audit_logs', 'owner_type'));
    }

    #[Test]
    public function an_installation_with_one_owner_for_each_entry_has_them_moved_to_the_accounts(): void
    {
        $user = User::factory()->create();
        $this->accountsMigration()->down();

        // What the log looked like before: one owner in two columns on the entry.
        $owned = DB::table('audit_logs')->insertGetId(['actor_label' => 'A', 'event' => 'users.ban', 'outcome' => 'succeeded', 'owner_type' => User::class, 'owner_id' => $user->id, 'created_at' => now()]);
        $nobodys = DB::table('audit_logs')->insertGetId(['actor_label' => 'A', 'event' => 'system.update', 'outcome' => 'succeeded', 'created_at' => now()]);

        try {
            $this->accountsMigration()->up();

            $this->assertSame([[User::class, $user->id]], DB::table('audit_log_accounts')->where('audit_log_id', $owned)->get()->map(fn ($row) => [$row->owner_type, (int) $row->owner_id])->all());
            $this->assertSame(0, DB::table('audit_log_accounts')->where('audit_log_id', $nobodys)->count());
            $this->assertFalse(Schema::hasColumn('audit_logs', 'owner_id'));
        } finally {
            if (! Schema::hasTable('audit_log_accounts')) {
                $this->accountsMigration()->up();
            }

            DB::table('audit_logs')->whereIn('id', [$owned, $nobodys])->delete();
        }
    }

    #[Test]
    public function rolling_back_keeps_one_account_for_each_entry(): void
    {
        [$first, $second] = User::factory()->count(2)->create()->all();
        $entry = AuditLog::factory()->about($second, $first)->create();

        try {
            $this->accountsMigration()->down();

            $kept = DB::table('audit_logs')->where('id', $entry->id)->first();

            $this->assertSame(User::class, $kept->owner_type);
            $this->assertSame(min($first->id, $second->id), (int) $kept->owner_id, 'the first of them');
        } finally {
            if (Schema::hasColumn('audit_logs', 'owner_type')) {
                $this->accountsMigration()->up();
            }

            DB::table('audit_logs')->where('id', $entry->id)->delete();
        }
    }

    #[Test]
    public function the_accounts_go_with_their_entry_and_stay_when_the_account_goes(): void
    {
        $user = User::factory()->create();
        $entry = AuditLog::factory()->about($user)->create();

        $user->delete();
        $this->assertSame(1, DB::table('audit_log_accounts')->where('audit_log_id', $entry->id)->count(), 'the log outlives the account');

        DB::table('audit_logs')->where('id', $entry->id)->delete();
        $this->assertSame(0, DB::table('audit_log_accounts')->where('audit_log_id', $entry->id)->count(), 'but goes with its entry');
    }

    #[Test]
    public function an_account_is_listed_once_for_an_entry(): void
    {
        $user = User::factory()->create();
        $entry = AuditLog::factory()->about($user)->create();

        try {
            DB::transaction(fn () => DB::table('audit_log_accounts')->insert(['audit_log_id' => $entry->id, 'owner_type' => User::class, 'owner_id' => $user->id]));
            $this->fail('the same account was listed twice');
        } catch (QueryException $exception) {
            $this->assertSame(1, DB::table('audit_log_accounts')->where('audit_log_id', $entry->id)->count());
        }
    }
}
