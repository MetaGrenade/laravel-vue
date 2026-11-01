<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\Support\ForeignKeyIndexes;
use Tests\TestCase;

/**
 * Every foreign key starts an index (docs/indexes.md).
 *
 * MySQL and MariaDB make that index when the constraint is made; PostgreSQL and SQLite do not, so a migration that only says
 * `->constrained()` is fine on MySQL and slow everywhere else: the page that asks for the rows of a parent reads the whole child
 * table, and so does deleting the parent. This fails on any database, so it is found when the migration is written.
 */
class ForeignKeyIndexTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_foreign_key_starts_an_index(): void
    {
        $this->assertSame([], $this->foreignKeysWithoutAnIndex(), implode("\n", [
            'These foreign keys have no index that starts with their columns. Give the column one where the table is made:',
            '    $table->foreignId(\'user_id\')->index()->constrained()->cascadeOnDelete();',
            'or, for a table that already exists, in a migration of its own: $table->index(\'user_id\');',
            'MySQL adds the index itself, which is why this only fails on SQLite and PostgreSQL.',
        ]));
    }

    #[Test]
    public function a_foreign_key_without_an_index_gets_one_and_down_takes_back_only_that(): void
    {
        if ($this->indexesItself()) {
            $this->markTestSkipped('InnoDB indexes a foreign key itself, so there is nothing for the migration to make.');
        }

        $this->assertTrue($this->startsAnIndex('forum_posts', ['forum_thread_id']));
        $this->assertContains('forum_posts_forum_thread_id_fk', $this->indexNames('forum_posts'));

        $others = $this->indexNamesNotMadeByIt('forum_posts');
        $this->assertNotSame([], $others, 'the table has indexes of its own to leave alone');

        $this->migration()->down();

        try {
            $this->assertFalse($this->startsAnIndex('forum_posts', ['forum_thread_id']), 'down() leaves the index it made');
            $this->assertSame($others, $this->indexNamesNotMadeByIt('forum_posts'), 'down() takes back only the indexes it made');
        } finally {
            $this->migration()->up();
        }

        $this->assertTrue($this->startsAnIndex('forum_posts', ['forum_thread_id']));
        $this->assertSame([], $this->foreignKeysWithoutAnIndex());
    }

    #[Test]
    public function running_it_again_makes_nothing_new(): void
    {
        $before = $this->allIndexNames();

        $this->migration()->up();

        $this->assertSame($before, $this->allIndexNames());
    }

    #[Test]
    public function an_index_a_person_made_is_neither_doubled_nor_dropped(): void
    {
        if ($this->indexesItself()) {
            $this->markTestSkipped('InnoDB indexes a foreign key itself, so there is nothing for the migration to make.');
        }

        $this->migration()->down();

        Schema::table('forum_posts', fn ($table) => $table->index(['forum_thread_id', 'created_at'], 'forum_posts_thread_and_time_index'));

        try {
            $this->migration()->up();

            $this->assertNotContains('forum_posts_forum_thread_id_fk', $this->indexNames('forum_posts'), 'a column that already starts an index is not given a second');

            $this->migration()->down();

            $this->assertContains('forum_posts_thread_and_time_index', $this->indexNames('forum_posts'), 'down() takes back only the indexes the migration made');
        } finally {
            Schema::table('forum_posts', fn ($table) => $table->dropIndex('forum_posts_thread_and_time_index'));
            $this->migration()->up();
        }

        $this->assertSame([], $this->foreignKeysWithoutAnIndex());
    }

    #[Test]
    public function two_foreign_keys_on_one_column_get_one_index(): void
    {
        if ($this->indexesItself()) {
            $this->markTestSkipped('InnoDB indexes a foreign key itself, so there is nothing for the migration to make.');
        }

        Schema::create('fk_probe_parent_a', fn ($table) => $table->id());
        Schema::create('fk_probe_parent_b', fn ($table) => $table->id());
        Schema::create('fk_probe_child', function ($table) {
            $table->id();
            $table->unsignedBigInteger('parent_id');
            $table->foreign('parent_id', 'fk_probe_child_a')->references('id')->on('fk_probe_parent_a');
            $table->foreign('parent_id', 'fk_probe_child_b')->references('id')->on('fk_probe_parent_b');
        });

        try {
            $this->assertCount(2, Schema::getForeignKeys('fk_probe_child'), 'the table has two foreign keys on one column');

            $this->migration()->up();

            $this->assertSame(['fk_probe_child_parent_id_fk'], array_values(array_filter($this->indexNames('fk_probe_child'), fn (string $name) => str_ends_with($name, '_fk'))));
        } finally {
            Schema::dropIfExists('fk_probe_child');
            Schema::dropIfExists('fk_probe_parent_b');
            Schema::dropIfExists('fk_probe_parent_a');
        }
    }

    #[Test]
    public function no_index_name_is_longer_than_postgresql_keeps(): void
    {
        if ($this->indexesItself()) {
            $this->markTestSkipped('InnoDB indexes a foreign key itself, so there is nothing for the migration to make.');
        }

        // The longest foreign key in the schema fits with nothing to spare: 34 + 1 + 25 + 3 characters.
        $name = $this->nameOfIndexStarting('support_ticket_message_attachments', ['support_ticket_message_id']);

        $this->assertSame('support_ticket_message_attachments_support_ticket_message_id_fk', $name);

        foreach (Schema::getTables() as $table) {
            foreach ($this->indexNames($table['name']) as $index) {
                $this->assertLessThanOrEqual(63, strlen($index), "{$table['name']}.{$index} is longer than PostgreSQL keeps");
            }
        }
    }

    #[Test]
    public function a_name_too_long_for_postgresql_keeps_its_start_and_is_the_same_each_time(): void
    {
        // No index in the schema needs this today, so the cut is tried on its own.
        $migration = $this->migration();
        $name = fn (string $table, array $columns): string => (new ReflectionMethod($migration, 'nameOf'))->invoke($migration, $table, $columns);

        $long = $name('a_table_whose_name_is_quite_long_indeed', ['a_column_whose_name_is_long_too', 'and_another_one_after_it']);

        $this->assertSame(63, strlen($long));
        $this->assertStringStartsWith('a_table_whose_name_is_quite_long_indeed_a_column_wh_', $long);
        $this->assertMatchesRegularExpression('/_[0-9a-f]{8}_fk$/', $long);
        $this->assertSame($long, $name('a_table_whose_name_is_quite_long_indeed', ['a_column_whose_name_is_long_too', 'and_another_one_after_it']));

        // Two that differ only after the cut are still two names.
        $this->assertNotSame($long, $name('a_table_whose_name_is_quite_long_indeed', ['a_column_whose_name_is_long_too', 'and_another_one_after_that']));

        // A name that fits is left as it is.
        $this->assertSame('forum_posts_forum_thread_id_fk', $name('forum_posts', ['forum_thread_id']));
    }

    #[Test]
    public function on_mysql_there_is_nothing_for_it_to_do(): void
    {
        if (! $this->indexesItself()) {
            $this->markTestSkipped('Only MySQL and MariaDB index a foreign key themselves.');
        }

        // Not "no index ends in _fk": a foreign key given a name of its own ($table->foreign('col', 'short_name_fk')) is an index of
        // that name here, and a few older migrations did that to keep under MySQL's limit on names.
        $before = $this->allIndexNames();

        $this->migration()->up();
        $this->migration()->down();

        $this->assertSame($before, $this->allIndexNames());
    }

    private function migration(): object
    {
        return ForeignKeyIndexes::migration();
    }

    private function indexesItself(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    /**
     * @return list<string> "table(column, column)"
     */
    private function foreignKeysWithoutAnIndex(): array
    {
        $missing = [];

        foreach (Schema::getTables() as $table) {
            foreach (Schema::getForeignKeys($table['name']) as $foreignKey) {
                if (! $this->startsAnIndex($table['name'], $foreignKey['columns'])) {
                    $missing[] = $table['name'].'('.implode(', ', $foreignKey['columns']).')';
                }
            }
        }

        sort($missing);

        return $missing;
    }

    /**
     * @param  list<string>  $columns
     */
    private function startsAnIndex(string $table, array $columns): bool
    {
        return $this->nameOfIndexStarting($table, $columns) !== null;
    }

    /**
     * @param  list<string>  $columns
     */
    private function nameOfIndexStarting(string $table, array $columns): ?string
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (array_slice($index['columns'], 0, count($columns)) === $columns) {
                return $index['name'];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function indexNames(string $table): array
    {
        return array_map(fn (array $index) => $index['name'], Schema::getIndexes($table));
    }

    /**
     * @return list<string>
     */
    private function indexNamesNotMadeByIt(string $table): array
    {
        return array_values(array_filter($this->indexNames($table), fn (string $name) => ! str_ends_with($name, '_fk')));
    }

    /**
     * @return list<string>
     */
    private function allIndexNames(): array
    {
        $names = [];

        foreach (Schema::getTables() as $table) {
            foreach ($this->indexNames($table['name']) as $name) {
                $names[] = $table['name'].'.'.$name;
            }
        }

        sort($names);

        return $names;
    }
}
