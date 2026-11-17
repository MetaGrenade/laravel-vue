<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Two indexes for the log of requests made with an API key (docs/indexes.md).
 *
 * A key's limits count its rows since a time, Admin → Tokens counts and lists by time, and the daily prune deletes by time; the only
 * index was the foreign key's. The columns and their order are written out here, and not read from the migration, so changing the
 * migration alone fails: the same columns the other way round serve another question.
 */
class ApiRequestLogIndexesTest extends TestCase
{
    use RefreshDatabase;

    private const FILE = '2026_11_09_000002_index_the_api_request_log.php';

    /** What the database or down() gives the foreign key's column when the index it stood on is taken away. */
    private const FOR_THE_FOREIGN_KEY = ['token_logs_personal_access_token_id_foreign', 'token_logs_personal_access_token_id_fk'];

    #[Test]
    public function a_keys_rows_since_a_time_are_one_stretch_of_an_index(): void
    {
        $this->assertSame('token_logs_by_key_and_time', $this->nameOfIndexWithColumns(['personal_access_token_id', 'created_at']));
    }

    #[Test]
    public function the_rows_of_all_keys_by_time_are_one_stretch_of_another_that_holds_the_key_too(): void
    {
        $this->assertSame('token_logs_by_time_and_key', $this->nameOfIndexWithColumns(['created_at', 'personal_access_token_id']));
    }

    #[Test]
    public function the_foreign_key_is_still_there_and_stands_on_an_index_that_starts_with_its_column(): void
    {
        $this->assertTrue($this->hasForeignKey());
        $this->assertNotSame([], $this->namesOfIndexesStarting(['personal_access_token_id']));
    }

    #[Test]
    public function down_takes_back_what_up_made_and_nothing_else_and_leaves_the_foreign_key_standing(): void
    {
        $before = $this->indexNames();
        $theOthers = array_values(array_diff($before, ['token_logs_by_key_and_time', 'token_logs_by_time_and_key']));

        $this->migration()->down();

        try {
            $after = $this->indexNames();

            $this->assertNull($this->nameOfIndexWithColumns(['personal_access_token_id', 'created_at']));
            $this->assertNull($this->nameOfIndexWithColumns(['created_at', 'personal_access_token_id']));
            $this->assertSame([], array_values(array_diff($theOthers, $after)), 'down() took back an index it did not make');
            // The foreign key's column is left with an index of its own: MySQL and MariaDB make one when the one the constraint stood on
            // goes, and down() gives the others the one that index_foreign_keys would have. No other index may appear.
            $this->assertSame([], array_values(array_diff($after, $theOthers, self::FOR_THE_FOREIGN_KEY)));
            $this->assertTrue($this->hasForeignKey(), 'the constraint is back after the index it stood on was dropped');
            $this->assertNotSame([], $this->namesOfIndexesStarting(['personal_access_token_id']), 'and it has an index of its own again');

            // Where index_foreign_keys is in charge of those indexes, the one given is written in its list, so its own down() takes it back.
            if (! $this->indexesItself()) {
                $this->assertTrue(DB::table('foreign_key_indexes')->where(['table_name' => 'token_logs', 'index_name' => 'token_logs_personal_access_token_id_fk'])->exists());
            }
        } finally {
            $this->migration()->up();
        }

        $this->assertSame([], array_values(array_diff($before, $this->indexNames())), 'up() makes the same ones again');
        $this->assertSame([], array_values(array_diff($this->indexNames(), $before, self::FOR_THE_FOREIGN_KEY)), 'and no more than the foreign key was given');
        $this->assertTrue($this->hasForeignKey());
    }

    #[Test]
    public function a_database_that_already_has_an_index_of_the_foreign_keys_own_is_not_given_a_second_by_down(): void
    {
        // down() and up() again leaves the foreign key's own index beside the two: what a database has that ran index_foreign_keys first.
        $this->migration()->down();
        $this->migration()->up();

        try {
            $this->migration()->down();

            $this->assertCount(1, $this->namesOfIndexesStarting(['personal_access_token_id']), 'one index starts with the foreign key, and it is not a copy of another');
            $this->assertTrue($this->hasForeignKey());
        } finally {
            $this->migration()->up();
        }
    }

    #[Test]
    public function running_it_again_makes_nothing_new(): void
    {
        $before = $this->indexNames();

        $this->migration()->up();

        $this->assertSame($before, $this->indexNames());
    }

    #[Test]
    public function down_copes_with_an_index_somebody_already_took_away(): void
    {
        Schema::table('token_logs', fn ($table) => $table->dropIndex('token_logs_by_time_and_key'));

        try {
            $this->migration()->down();

            $this->assertNull($this->nameOfIndexWithColumns(['personal_access_token_id', 'created_at']));
            $this->assertTrue($this->hasForeignKey());
        } finally {
            $this->migration()->up();
        }

        $this->assertNotNull($this->nameOfIndexWithColumns(['created_at', 'personal_access_token_id']));
    }

    #[Test]
    public function an_index_a_person_made_is_neither_doubled_nor_dropped(): void
    {
        $this->migration()->down();

        // Made before this migration ran, for instance to build them without a lock on a large database: one that starts with the
        // columns of the first and goes on, under the name Laravel would have given, and one that starts with those of the other.
        $theirKey = 'token_logs_personal_access_token_id_created_at_index';
        $theirTime = 'logs_by_time';

        Schema::table('token_logs', function ($table) use ($theirKey, $theirTime) {
            $table->index(['personal_access_token_id', 'created_at', 'id'], $theirKey);
            $table->index(['created_at', 'personal_access_token_id', 'id'], $theirTime);
        });

        try {
            $this->migration()->up();

            $this->assertSame([$theirKey], $this->namesOfIndexesStarting(['personal_access_token_id', 'created_at']), 'not doubled');
            $this->assertSame([$theirTime], $this->namesOfIndexesStarting(['created_at', 'personal_access_token_id']), 'nor the other');

            $this->migration()->down();

            $this->assertContains($theirKey, $this->indexNames(), 'down() took back an index it did not make');
            $this->assertContains($theirTime, $this->indexNames(), 'down() took back another it did not make');
            $this->assertTrue($this->hasForeignKey());
        } finally {
            $this->dropTheirs($theirKey);
            $this->dropTheirs($theirTime);
            $this->migration()->up();
        }

        $this->assertNotNull($this->nameOfIndexWithColumns(['personal_access_token_id', 'created_at']));
    }

    private function migration(): object
    {
        return require database_path('migrations/'.self::FILE);
    }

    /**
     * An index of somebody's that starts with the foreign key's column cannot be dropped on MySQL while the constraint stands on it.
     */
    private function dropTheirs(string $name): void
    {
        if (! in_array($name, $this->indexNames(), true)) {
            return;
        }

        $mysql = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
        $startsWithTheKey = str_contains($name, 'personal_access_token_id');

        if ($mysql && $startsWithTheKey && $this->hasForeignKey()) {
            Schema::table('token_logs', fn ($table) => $table->dropForeign(['personal_access_token_id']));
            Schema::table('token_logs', function ($table) use ($name) {
                $table->dropIndex($name);
                $table->foreign('personal_access_token_id')->references('id')->on('personal_access_tokens')->cascadeOnDelete();
            });

            return;
        }

        Schema::table('token_logs', fn ($table) => $table->dropIndex($name));
    }

    /**
     * InnoDB indexes a foreign key itself, so `index_foreign_keys` does nothing there.
     */
    private function indexesItself(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function hasForeignKey(): bool
    {
        foreach (Schema::getForeignKeys('token_logs') as $foreignKey) {
            if ($foreignKey['columns'] === ['personal_access_token_id'] && $foreignKey['foreign_table'] === 'personal_access_tokens') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function indexNames(): array
    {
        $names = array_map(fn (array $index) => $index['name'], Schema::getIndexes('token_logs'));

        sort($names);

        return $names;
    }

    /**
     * @param  list<string>  $columns
     */
    private function nameOfIndexWithColumns(array $columns): ?string
    {
        foreach (Schema::getIndexes('token_logs') as $index) {
            if ($index['columns'] === $columns) {
                return $index['name'];
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $columns
     * @return list<string>
     */
    private function namesOfIndexesStarting(array $columns): array
    {
        $names = [];

        foreach (Schema::getIndexes('token_logs') as $index) {
            if (array_slice($index['columns'], 0, count($columns)) === $columns) {
                $names[] = $index['name'];
            }
        }

        sort($names);

        return $names;
    }
}
