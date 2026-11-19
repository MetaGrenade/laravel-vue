<?php

namespace Tests\Feature\Database;

use App\Support\Members\EmailLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The index on `LOWER(email)` that finds an address typed in other letters (App\Support\Members\EmailLookup) without reading every member.
 *
 * SQLite and PostgreSQL compare text exactly and need it; MySQL and MariaDB compare without regard to case and are not given one.
 * DDL commits the test's transaction on MySQL, which is never asked to change the schema here.
 */
class LowercaseEmailIndexTest extends TestCase
{
    use RefreshDatabase;

    private const INDEX = 'users_lower_email_index';

    private function migration(): object
    {
        return require database_path('migrations/2026_11_19_000000_index_the_lowercase_email.php');
    }

    private function exactDatabase(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['sqlite', 'pgsql'], true);
    }

    private function indexExists(): bool
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => DB::selectOne("select name from sqlite_master where type = 'index' and name = ?", [self::INDEX]) !== null,
            'pgsql' => DB::selectOne('select indexname from pg_indexes where indexname = ?', [self::INDEX]) !== null,
            default => false,
        };
    }

    #[Test]
    public function the_databases_that_compare_exactly_have_the_index_and_the_others_do_not(): void
    {
        $this->assertSame($this->exactDatabase(), $this->indexExists());
    }

    #[Test]
    public function the_index_rolls_back_and_forward_cleanly_and_twice_over(): void
    {
        if (! $this->exactDatabase()) {
            $this->markTestSkipped('Only SQLite and PostgreSQL are given the index.');
        }

        $this->migration()->down();
        $this->assertFalse($this->indexExists());

        // Taking back what is not there, and making what is there, are no errors: a site that built it by hand first (without the lock
        // `CREATE INDEX` holds) runs the migration all the same.
        $this->migration()->down();
        $this->migration()->up();
        $this->migration()->up();
        $this->assertTrue($this->indexExists());
    }

    #[Test]
    public function the_question_that_finds_an_address_in_other_letters_is_answered_by_the_index(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('The plan is read on SQLite, where a table this small is still searched by its index. PostgreSQL would read it whole.');
        }

        // The very query the lookup runs, as it ran it.
        DB::enableQueryLog();
        EmailLookup::find('Ada@Example.com');
        $queries = collect(DB::getQueryLog())->filter(fn (array $query) => str_contains($query['query'], 'LOWER(email)'));
        DB::disableQueryLog();

        $this->assertCount(1, $queries, 'the lookup did not ask the question this index answers');

        $query = $queries->first();
        $plan = collect(DB::select('explain query plan '.$query['query'], $query['bindings']))
            ->map(fn ($row) => $row->detail)
            ->implode(' | ');

        $this->assertStringContainsString(self::INDEX, $plan, 'the lookup reads the whole table: '.$plan);
    }
}
