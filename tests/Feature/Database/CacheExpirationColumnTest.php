<?php

namespace Tests\Feature\Database;

use App\Models\SupportAssignmentRule;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The database cache after January 2028.
 *
 * An entry kept forever is written to run out ten years from now, which no 32-bit column holds after 22 January 2028. SQLite keeps any
 * 64-bit number in any integer column, so on SQLite these pass with or without the migration that widens the columns: what fails without
 * it is the run on MySQL, MariaDB and PostgreSQL, which CI makes for every pull request. DDL commits the test's transaction on MySQL, so
 * the test that changes the schema puts it back and takes its rows away.
 */
class CacheExpirationColumnTest extends TestCase
{
    use RefreshDatabase;

    private const FILE = '2026_11_28_000000_widen_the_cache_expiration_columns.php';

    /** The latest time a 32-bit integer holds: 03:14:07 UTC on 19 January 2038. */
    private const LARGEST_SMALL = 2147483647;

    protected function setUp(): void
    {
        parent::setUp();

        // The tests keep the cache in memory. This is the store a new install starts with (.env.example).
        config(['cache.default' => 'database']);
        $this->app->forgetInstance('cache.store');
    }

    #[Test]
    public function an_entry_can_be_kept_forever_after_january_2028(): void
    {
        foreach (['2028-01-22 03:15:00', '2040-06-01 00:00:00'] as $now) {
            $this->travelTo($now);

            $this->assertTrue(Cache::forever('kept', 'first'), $now);
            $this->assertTrue(Cache::forever('kept', 'second'), $now.', written over the one that is there');
            $this->assertSame('second', Cache::get('kept'), $now);
            $this->assertGreaterThan(self::LARGEST_SMALL, (int) DB::table('cache')->max('expiration'), $now);
        }
    }

    #[Test]
    public function a_lock_can_be_held_past_january_2038(): void
    {
        $this->travelTo('2030-06-01 00:00:00');

        $this->assertTrue(Cache::lock('long', 315360000)->get());
        $this->assertFalse(Cache::lock('long', 315360000)->get(), 'it is held');
        $this->assertGreaterThan(self::LARGEST_SMALL, (int) DB::table('cache_locks')->max('expiration'));
    }

    #[Test]
    public function a_deploy_and_a_support_ticket_still_work_in_2028(): void
    {
        $member = User::factory()->create();

        $this->travelTo('2028-02-01 12:00:00');

        $this->artisan('queue:restart')->assertSuccessful();
        $this->artisan('schedule:pause')->assertSuccessful();
        $this->assertNotNull(Cache::get('illuminate:queue:restart'));

        // Opening a ticket reads the assignment rules, which are kept in the cache forever.
        $this->actingAs($member)
            ->post(route('support.tickets.store'), ['subject' => 'It is 2028', 'body' => 'A ticket can still be opened.', 'priority' => 'medium'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1, SupportTicket::query()->count());
        $this->assertCount(0, SupportAssignmentRule::cachedForAssignment());
        $this->assertGreaterThan(self::LARGEST_SMALL, (int) DB::table('cache')->max('expiration'));
    }

    #[Test]
    public function the_columns_still_have_to_be_filled_and_a_key_is_still_there_once(): void
    {
        foreach (['cache', 'cache_locks'] as $table) {
            $expiration = collect(Schema::getColumns($table))->firstWhere('name', 'expiration');

            $this->assertFalse($expiration['nullable'], $table);
            $this->assertTrue($this->keyIsThePrimaryKey($table), $table);
        }
    }

    #[Test]
    public function rolling_back_throws_away_only_what_no_longer_fits(): void
    {
        $keys = fn (string $table) => DB::table($table)->orderBy('key')->pluck('key')->all();

        foreach (['cache' => 'value', 'cache_locks' => 'owner'] as $table => $other) {
            DB::table($table)->insert([
                ['key' => 'fits', $other => 'a', 'expiration' => self::LARGEST_SMALL],
                ['key' => 'too-far', $other => 'b', 'expiration' => self::LARGEST_SMALL + 1],
            ]);
        }

        try {
            try {
                $this->migration()->down();

                $this->assertSame(['fits'], $keys('cache'));
                $this->assertSame(['fits'], $keys('cache_locks'));

                // As the databases name a 32-bit integer (SQLite has the one name for both widths).
                $this->assertContains(Schema::getColumnType('cache', 'expiration'), ['int', 'int4', 'integer']);
                $this->assertContains(Schema::getColumnType('cache_locks', 'expiration'), ['int', 'int4', 'integer']);
            } finally {
                $this->migration()->up();
            }

            foreach (['cache' => 'value', 'cache_locks' => 'owner'] as $table => $other) {
                $this->assertSame(['fits'], $keys($table), 'what was there is still there');
                $this->assertTrue($this->keyIsThePrimaryKey($table), $table);
                $this->assertContains(Schema::getColumnType($table, 'expiration'), ['bigint', 'int8', 'integer']);

                DB::table($table)->insert(['key' => 'far-again', $other => 'c', 'expiration' => 4000000000]);

                $this->assertSame(4000000000, (int) DB::table($table)->where('key', 'far-again')->value('expiration'), $table);
            }
        } finally {
            DB::table('cache')->delete();
            DB::table('cache_locks')->delete();
        }
    }

    private function keyIsThePrimaryKey(string $table): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn (array $index) => $index['primary'] && $index['columns'] === ['key']);
    }

    private function migration(): object
    {
        return require database_path('migrations/'.self::FILE);
    }
}
