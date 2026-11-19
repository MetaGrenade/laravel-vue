<?php

namespace Tests\Feature\Privacy;

use App\Models\Address;
use App\Models\TokenLog;
use App\Models\User;
use App\Support\Privacy\MemberLeftovers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\PopulatesAMember;
use Tests\TestCase;

/**
 * `php artisan metaforger:members:forget-leftovers`: what accounts deleted before MemberLeftovers existed left behind.
 *
 * Such an account is made here by deleting it with the model's events off, which is what the old code did in effect (nothing ran).
 */
class ForgetDeletedMembersLeftoversTest extends TestCase
{
    use PopulatesAMember;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        config(['session.driver' => 'database']);
    }

    private function deletedTheOldWay(User $member): void
    {
        User::withoutEvents(fn () => $member->delete());
    }

    #[Test]
    public function it_deletes_what_a_gone_account_left_and_nothing_of_anybody_else(): void
    {
        $gone = User::factory()->create();
        $here = User::factory()->create();

        $this->populate($gone);
        $this->populate($here);
        $kept = $this->rows($gone, $here);
        $this->deletedTheOldWay($gone);

        $this->assertSame($kept['personal_access_tokens'], $this->rows($gone, $here)['personal_access_tokens'], 'the old way left everything');

        $this->artisan('metaforger:members:forget-leftovers')->assertSuccessful();

        $after = $this->rows($gone, $here);

        // Every table counted, the Discord package's among them: only the member who is here keeps rows (what the cascade took with the
        // account, the exports and the tickets, is halved too).
        foreach ($kept as $table => $count) {
            $this->assertSame($count / 2, $after[$table], "$table: only the member who is here keeps rows");
        }
    }

    #[Test]
    public function a_dry_run_counts_and_deletes_nothing(): void
    {
        $gone = User::factory()->create();
        $this->populate($gone);
        $before = $this->rows($gone);
        $this->deletedTheOldWay($gone);

        $this->artisan('metaforger:members:forget-leftovers', ['--dry-run' => true])
            ->expectsOutputToContain('Rows that would be deleted')
            ->assertSuccessful();

        $this->assertSame($before['personal_access_tokens'], $this->rows($gone)['personal_access_tokens']);
        $this->assertSame($before['notifications'], $this->rows($gone)['notifications']);
        $this->assertSame($before['token_logs'], $this->rows($gone)['token_logs']);
    }

    #[Test]
    public function the_count_it_reports_is_what_was_there(): void
    {
        $gone = User::factory()->create();
        $this->populate($gone, logsPerKey: 4);
        $this->deletedTheOldWay($gone);

        $counts = app(MemberLeftovers::class)->ofMembersWhoAreGone(delete: false);

        $this->assertSame(2, $counts['personal_access_tokens']);
        $this->assertSame(8, $counts['token_logs']);
        $this->assertSame(3, $counts['notifications']);
        $this->assertSame(2, $counts['addresses']);
        $this->assertSame(2, $counts['user_entitlements']);
        $this->assertSame(1, $counts['sessions']);
        $this->assertSame(1, $counts['password_reset_tokens']);

        // The Discord package's tables are there when the package is installed, and then each has the member's one row.
        foreach ($this->discordTables() as $table) {
            if (Schema::hasTable($table)) {
                $this->assertSame(1, $counts[$table], $table);
            } else {
                $this->assertArrayNotHasKey($table, $counts, "$table: a table the site does not have is not looked at");
            }
        }

        $deleted = app(MemberLeftovers::class)->ofMembersWhoAreGone();

        $this->assertSame($counts, $deleted, 'what it said would go is what went');
        $this->assertSame(array_fill_keys(array_keys($counts), 0), app(MemberLeftovers::class)->ofMembersWhoAreGone(), 'and a second run finds nothing');
    }

    #[Test]
    public function rows_of_something_that_is_not_a_member_and_of_guests_are_kept(): void
    {
        $member = User::factory()->create();
        $this->deletedTheOldWay($member);

        // A team with the id of the account that is gone; a guest's session (no user); a key of a type the site does not know.
        DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'type' => 'x', 'notifiable_type' => 'team', 'notifiable_id' => $member->id, 'data' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        Address::factory()->create(['owner_type' => 'team', 'owner_id' => $member->id]);
        DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => null, 'ip_address' => '203.0.113.9', 'user_agent' => 'x', 'payload' => '', 'last_activity' => now()->timestamp]);
        DB::table('personal_access_tokens')->insert(['tokenable_type' => 'team', 'tokenable_id' => $member->id, 'name' => 'team key', 'token' => hash('sha256', 'team'), 'abilities' => '["*"]', 'created_at' => now(), 'updated_at' => now()]);

        $this->artisan('metaforger:members:forget-leftovers')->assertSuccessful();

        foreach (['notifications', 'addresses', 'sessions', 'personal_access_tokens'] as $table) {
            $this->assertSame(1, DB::table($table)->count(), "$table: not a member's, so not touched");
        }
    }

    #[Test]
    public function a_reset_token_of_an_address_a_member_holds_is_kept_whatever_its_case(): void
    {
        $member = User::factory()->create(['email' => 'ada@example.com']);
        $gone = User::factory()->create(['email' => 'gone@example.com']);
        $this->deletedTheOldWay($gone);

        // One for an address a member holds, written in other letters (the table's key compares exactly on SQLite and PostgreSQL), and one
        // for an address nobody holds any more.
        DB::table('password_reset_tokens')->insert([
            ['email' => 'ADA@EXAMPLE.COM', 'token' => hash('sha256', 'a'), 'created_at' => now()],
            ['email' => 'gone@example.com', 'token' => hash('sha256', 'b'), 'created_at' => now()],
        ]);

        $this->artisan('metaforger:members:forget-leftovers')->assertSuccessful();

        $this->assertSame(['ADA@EXAMPLE.COM'], DB::table('password_reset_tokens')->pluck('email')->all());
        $this->assertNotNull($member->fresh());
    }

    #[Test]
    public function more_rows_than_one_chunk_are_all_deleted(): void
    {
        $member = User::factory()->create();

        foreach (array_chunk(range(1, 2300), 500) as $chunk) {
            DB::table('notifications')->insert(array_map(fn (int $n) => [
                'id' => (string) Str::uuid(),
                'type' => 'x',
                'notifiable_type' => $member->getMorphClass(),
                'notifiable_id' => $member->id,
                'data' => '{}',
                'created_at' => now(),
                'updated_at' => now(),
            ], $chunk));
        }

        $this->deletedTheOldWay($member);

        $this->artisan('metaforger:members:forget-leftovers')->assertSuccessful();

        $this->assertSame(0, DB::table('notifications')->count());
    }

    #[Test]
    public function the_log_of_a_gone_members_keys_goes_before_the_keys(): void
    {
        $member = User::factory()->create();
        $this->populate($member, logsPerKey: 6);
        $this->deletedTheOldWay($member);

        $this->assertSame(12, TokenLog::count());

        $this->artisan('metaforger:members:forget-leftovers')->assertSuccessful();

        $this->assertSame(0, TokenLog::count());
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }
}
