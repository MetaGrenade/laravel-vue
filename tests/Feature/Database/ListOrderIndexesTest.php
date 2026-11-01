<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Indexes for the order of four lists (docs/indexes.md).
 *
 * A foreign key index finds the rows of a parent and does not put them in the order a page shows them. The columns and their order
 * are written out here, and not read from the migration, so changing the migration alone fails: an index with its columns the
 * other way round serves the filter and not the sort.
 */
class ListOrderIndexesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function lists(): array
    {
        return [
            'the latest posts on the forum index' => ['forum_posts', ['created_at']],
            'the approved comments of a blog post, and the count behind "most commented"' => ['blog_comments', ['status', 'blog_id']],
            'the threads of a board, pinned first and then the latest reply' => ['forum_threads', ['is_published', 'forum_board_id', 'is_pinned', 'last_posted_at', 'created_at']],
            'the member list, newest first, without the banned' => ['users', ['is_banned', 'created_at']],
        ];
    }

    /**
     * @param  list<string>  $columns
     */
    #[Test]
    #[DataProvider('lists')]
    public function each_list_has_an_index_with_the_columns_of_its_filter_then_its_sort(string $table, array $columns): void
    {
        $this->assertNotNull($this->nameOfIndexWithColumns($table, $columns), "{$table} has no index on exactly (".implode(', ', $columns).')');
    }

    #[Test]
    public function none_of_them_starts_with_a_foreign_key_column(): void
    {
        // MySQL gives a foreign key its own index. One that starts with the same column takes over from it, and then the foreign
        // key cannot be dropped, nor this index, until the foreign key is (error 1553 on rollback).
        foreach (self::lists() as [$table, $columns]) {
            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                $this->assertNotSame($foreignKey['columns'], array_slice($columns, 0, count($foreignKey['columns'])), "an index on {$table} starts with the foreign key (".implode(', ', $foreignKey['columns']).')');
            }
        }
    }

    #[Test]
    public function it_writes_down_what_it_makes(): void
    {
        $this->assertSame([
            'blog_comments.blog_comments_status_blog_id_index',
            'forum_posts.forum_posts_created_at_index',
            'forum_threads.forum_threads_board_order_index',
            'users.users_is_banned_created_at_index',
        ], $this->madeByIt());
    }

    #[Test]
    public function down_takes_back_exactly_what_up_made_and_the_list_with_it(): void
    {
        $before = $this->allIndexNames();
        $made = $this->madeByIt();

        $this->assertNotSame([], array_diff($before, $made), 'the schema has indexes of its own to leave alone');

        $this->migration()->down();

        try {
            $this->assertFalse(Schema::hasTable($this->registry()), 'down() takes the list away with the indexes');
            $this->assertSame(array_values(array_diff($before, $made)), $this->allIndexNames(), 'down() takes back the indexes it made and no other');
        } finally {
            $this->migration()->up();
        }

        $this->assertSame($before, $this->allIndexNames(), 'up() makes the same ones again');
        $this->assertSame($made, $this->madeByIt());
    }

    #[Test]
    public function running_it_again_makes_nothing_new(): void
    {
        $before = $this->allIndexNames();
        $made = $this->madeByIt();

        $this->migration()->up();

        $this->assertSame($before, $this->allIndexNames());
        $this->assertSame($made, $this->madeByIt());
    }

    #[Test]
    public function down_copes_with_an_index_somebody_already_took_away(): void
    {
        Schema::table('users', fn ($table) => $table->dropIndex('users_is_banned_created_at_index'));

        try {
            $this->migration()->down();

            $this->assertFalse(Schema::hasTable($this->registry()));
        } finally {
            $this->migration()->up();
        }

        $this->assertNotNull($this->nameOfIndexWithColumns('users', ['is_banned', 'created_at']));
    }

    #[Test]
    public function an_index_taken_away_by_hand_is_made_again_and_listed_once(): void
    {
        $made = $this->madeByIt();

        Schema::table('users', fn ($table) => $table->dropIndex('users_is_banned_created_at_index'));
        $this->assertNull($this->nameOfIndexWithColumns('users', ['is_banned', 'created_at']));

        try {
            $this->migration()->up();

            $this->assertSame('users_is_banned_created_at_index', $this->nameOfIndexWithColumns('users', ['is_banned', 'created_at']));
            $this->assertSame($made, $this->madeByIt(), 'it is listed once, not twice');
        } finally {
            $this->migration()->up();
        }
    }

    #[Test]
    public function an_index_a_person_made_is_neither_doubled_nor_dropped(): void
    {
        $this->migration()->down();

        // Indexes a person made before this migration ran, for instance to build them without a lock on a large database:
        //  - exactly what this migration would make, under the name it would give;
        //  - one that starts with the same columns and goes on, under another name;
        //  - one that has the name this migration would give but goes on past its columns;
        //  - one that has every column of the thread list, in an order that serves another sort: it does not cover this one.
        $theirs = [
            'forum_posts' => 'forum_posts_created_at_index',
            'users' => 'members_by_age',
            'blog_comments' => 'blog_comments_status_blog_id_index',
            'forum_threads' => 'forum_threads_other_order',
        ];

        Schema::table('forum_posts', fn ($table) => $table->index(['created_at'], $theirs['forum_posts']));
        Schema::table('users', fn ($table) => $table->index(['is_banned', 'created_at', 'id'], $theirs['users']));
        Schema::table('blog_comments', fn ($table) => $table->index(['status', 'blog_id', 'id'], $theirs['blog_comments']));
        Schema::table('forum_threads', fn ($table) => $table->index(['created_at', 'last_posted_at', 'is_pinned', 'forum_board_id', 'is_published'], $theirs['forum_threads']));

        try {
            $this->migration()->up();

            $this->assertSame([$theirs['forum_posts']], $this->namesOfIndexesStarting('forum_posts', ['created_at']), 'an index that is already there is not doubled');
            $this->assertSame([$theirs['users']], $this->namesOfIndexesStarting('users', ['is_banned', 'created_at']), 'nor one that starts with the same columns');
            $this->assertSame([$theirs['blog_comments']], $this->namesOfIndexesStarting('blog_comments', ['status', 'blog_id']), 'nor one that has its name');
            $this->assertNotNull($this->nameOfIndexWithColumns('forum_threads', ['is_published', 'forum_board_id', 'is_pinned', 'last_posted_at', 'created_at']), 'a list with nothing is given its index');
            $this->assertSame(['forum_threads.forum_threads_board_order_index'], $this->madeByIt(), 'it writes down only the one it made');

            $this->migration()->down();

            foreach ($theirs as $table => $name) {
                $this->assertContains($name, $this->indexNames($table), "down() took back {$table}.{$name}, which it did not make");
            }

            $this->assertNull($this->nameOfIndexWithColumns('forum_threads', ['is_published', 'forum_board_id', 'is_pinned', 'last_posted_at', 'created_at']), 'down() took back the one it made');
        } finally {
            foreach ($theirs as $table => $name) {
                if (in_array($name, $this->indexNames($table), true)) {
                    Schema::table($table, fn ($blueprint) => $blueprint->dropIndex($name));
                }
            }

            $this->migration()->up();
        }

        $this->assertNotNull($this->nameOfIndexWithColumns('users', ['is_banned', 'created_at']));
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_11_11_000000_index_the_lists.php');
    }

    private function registry(): string
    {
        return $this->migration()::REGISTRY;
    }

    /**
     * @return list<string>
     */
    private function indexNames(string $table): array
    {
        return array_map(fn (array $index) => $index['name'], Schema::getIndexes($table));
    }

    /**
     * @param  list<string>  $columns
     */
    private function nameOfIndexWithColumns(string $table, array $columns): ?string
    {
        foreach (Schema::getIndexes($table) as $index) {
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
    private function namesOfIndexesStarting(string $table, array $columns): array
    {
        $names = [];

        foreach (Schema::getIndexes($table) as $index) {
            if (array_slice($index['columns'], 0, count($columns)) === $columns) {
                $names[] = $index['name'];
            }
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private function madeByIt(): array
    {
        $made = DB::table($this->registry())->get()->map(fn (object $row) => $row->table_name.'.'.$row->index_name)->all();

        sort($made);

        return $made;
    }

    /**
     * @return list<string>
     */
    private function allIndexNames(): array
    {
        $names = [];
        $registry = $this->registry();

        foreach (Schema::getTables() as $table) {
            if ($table['name'] === $registry) {
                continue;
            }

            foreach ($this->indexNames($table['name']) as $name) {
                $names[] = $table['name'].'.'.$name;
            }
        }

        sort($names);

        return $names;
    }
}
