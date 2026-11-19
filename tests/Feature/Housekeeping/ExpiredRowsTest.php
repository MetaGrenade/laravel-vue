<?php

namespace Tests\Feature\Housekeeping;

use App\Jobs\PruneExpiredCacheEntries;
use App\Models\User;
use Illuminate\Cache\RateLimiter;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Rows that have run out and that nothing deleted: the database cache and the password reset tokens.
 *
 * Laravel's database cache deletes an expired row only when that key is asked for again, so a key nobody asks for again stays for ever
 * (every failed sign-in leaves two rows of the rate limiter, whatever address was tried). A password reset token expires after an hour
 * and is deleted only by `auth:clear-resets`, which nothing ran: the address of a member who asked and never used it stayed for good.
 */
class ExpiredRowsTest extends TestCase
{
    use RefreshDatabase;

    private function keys(string $table = 'cache'): array
    {
        return DB::table($table)->orderBy('key')->pluck('key')->map(fn (string $key) => str_replace('metaforger-cache-', '', $key))->all();
    }

    // --- The database cache -------------------------------------------------------------------------------

    public function test_a_row_that_has_run_out_is_deleted_and_one_that_has_not_is_kept(): void
    {
        $cache = Cache::store('database');
        $cache->put('short', 'a', 60);
        $cache->put('long', 'b', 86400);
        $cache->forever('forever', 'c');

        $this->travel(2)->hours();
        (new PruneExpiredCacheEntries)->handle();

        $this->assertSame(['forever', 'long'], $this->keys());
        $this->assertSame('b', $cache->get('long'));
        $this->assertSame('c', $cache->get('forever'));
    }

    public function test_what_failed_sign_ins_leave_behind_is_deleted_once_it_has_run_out(): void
    {
        // Two rows for each: the counter and its timer.
        $limiter = new RateLimiter(Cache::store('database'));

        foreach (range(1, 5) as $n) {
            $limiter->hit("nobody-{$n}@example.com|203.0.113.9", 60);
        }

        $this->travel(2)->days();
        $this->assertCount(10, $this->keys(), 'they stay when nothing deletes them');

        (new PruneExpiredCacheEntries)->handle();

        $this->assertSame([], $this->keys());
    }

    public function test_more_rows_than_one_chunk_are_all_deleted(): void
    {
        $past = now()->subDay()->getTimestamp();

        foreach (array_chunk(range(1, 5300), 500) as $chunk) {
            DB::table('cache')->insert(array_map(fn (int $n) => ['key' => "metaforger-cache-old-{$n}", 'value' => 's:1:"x";', 'expiration' => $past], $chunk));
        }

        Cache::store('database')->put('live', 'x', 3600);

        (new PruneExpiredCacheEntries)->handle();

        $this->assertSame(['live'], $this->keys());
    }

    public function test_a_row_that_runs_out_this_second_is_deleted_and_one_that_runs_out_next_is_not(): void
    {
        $now = now()->getTimestamp();

        DB::table('cache')->insert([
            ['key' => 'metaforger-cache-now', 'value' => 's:1:"x";', 'expiration' => $now],
            ['key' => 'metaforger-cache-next', 'value' => 's:1:"x";', 'expiration' => $now + 1],
        ]);

        (new PruneExpiredCacheEntries)->handle();

        $this->assertSame(['next'], $this->keys());
    }

    public function test_every_database_store_is_cleaned_each_table_once_and_the_others_are_left_alone(): void
    {
        // A second table (DDL ends the test's transaction on MySQL, so only this table gets rows here, and it is dropped again).
        Schema::create('cache_elsewhere', function ($table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });

        try {
            config(['cache.stores' => [
                'database' => ['driver' => 'database', 'table' => 'cache'],
                'also' => ['driver' => 'database', 'table' => 'cache'],
                'elsewhere' => ['driver' => 'database', 'table' => 'cache_elsewhere'],
                'array' => ['driver' => 'array'],
                'file' => ['driver' => 'file', 'path' => storage_path('framework/cache/data')],
            ]]);

            DB::table('cache_elsewhere')->insert([
                ['key' => 'old', 'value' => 's:1:"x";', 'expiration' => now()->subHour()->getTimestamp()],
                ['key' => 'new', 'value' => 's:1:"x";', 'expiration' => now()->addHour()->getTimestamp()],
            ]);

            (new PruneExpiredCacheEntries)->handle();

            $this->assertSame(['new'], DB::table('cache_elsewhere')->pluck('key')->all());
        } finally {
            Schema::dropIfExists('cache_elsewhere');
        }
    }

    public function test_a_site_with_no_database_cache_has_nothing_to_clean(): void
    {
        config(['cache.stores' => ['array' => ['driver' => 'array']]]);

        DB::table('cache')->insert(['key' => 'metaforger-cache-old', 'value' => 's:1:"x";', 'expiration' => now()->subDay()->getTimestamp()]);

        (new PruneExpiredCacheEntries)->handle();

        $this->assertSame(1, DB::table('cache')->count(), 'a table no store of the site uses is not its to clean');
    }

    // --- Password reset tokens ----------------------------------------------------------------------------

    public function test_the_scheduler_clears_the_reset_tokens_that_have_run_out(): void
    {
        $gone = User::factory()->create(['email' => 'asked-and-never-used@example.com']);
        Password::createToken($gone);

        $this->travel(3)->hours();

        $recent = User::factory()->create(['email' => 'asked-just-now@example.com']);
        Password::createToken($recent);

        $this->assertSame(1, $this->events('auth:clear-resets')->count(), 'the scheduler does not run it');

        $this->artisan('auth:clear-resets')->assertSuccessful();

        $this->assertSame(['asked-just-now@example.com'], DB::table('password_reset_tokens')->pluck('email')->all());
    }

    // --- The schedule -------------------------------------------------------------------------------------

    public function test_the_cache_is_cleaned_every_hour_and_the_reset_tokens_every_day(): void
    {
        $cache = $this->events('PruneExpiredCacheEntries');
        $resets = $this->events('auth:clear-resets');

        $this->assertCount(1, $cache);
        $this->assertSame('0 * * * *', $cache->first()->expression);
        $this->assertCount(1, $resets);
        $this->assertSame('0 3 * * *', $resets->first()->expression);
    }

    /**
     * @return Collection<int, Event>
     */
    private function events(string $needle)
    {
        // The schedule is put together when the console kernel is booted, which a command does.
        $this->artisan('schedule:list')->assertSuccessful();

        return collect(app(Schedule::class)->events())
            ->filter(fn (Event $event) => str_contains((string) $event->description, $needle) || str_contains((string) $event->command, $needle) || str_contains((string) ($event->getSummaryForDisplay()), $needle))
            ->values();
    }
}
