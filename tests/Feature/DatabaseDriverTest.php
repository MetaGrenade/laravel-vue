<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards the CI database matrix: when a job says it runs on MySQL or
 * PostgreSQL, the suite must really be using that database rather than
 * silently falling back to SQLite.
 */
class DatabaseDriverTest extends TestCase
{
    #[Test]
    public function the_suite_runs_on_the_database_the_environment_asks_for(): void
    {
        $expected = getenv('EXPECTED_DB_DRIVER') ?: 'sqlite';

        $this->assertSame($expected, DB::connection()->getDriverName());
    }
}
