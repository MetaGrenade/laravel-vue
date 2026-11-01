<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the order of four lists (docs/indexes.md).
 *
 * A foreign key index finds the rows of a parent; it does not put them in the order a page shows them, so a page that asks for the
 * newest, or the pinned first, still read every row to sort them. Measured on PostgreSQL with 1,000,000 posts, 100,000 threads,
 * 200,000 comments and 200,000 members: the latest posts on the forum index 142 ms to about 1 ms, a board of 10,000 threads 7 ms to
 * 0.2 ms, the member list 21 ms to 0.03 ms, the most commented posts on a blog page 186 ms to 23 ms. MariaDB, on a smaller set, is
 * faster the same way. Writing to those tables takes 10 to 20 percent longer.
 *
 * Which indexes are this migration's is written down, in the `list_order_indexes` table, as each one is made, and `down()` takes
 * back exactly those. It never goes by a name: an index somebody made before (to build it without a lock on a large database,
 * say) is theirs, and is left alone when it starts with the same columns, whatever it is called.
 */
return new class extends Migration
{
    /** The indexes this migration made. */
    public const REGISTRY = 'list_order_indexes';

    /**
     * Table, columns, name. Equal columns first and the ones a page sorts by last, so one index serves the filter and the order.
     * None starts with a foreign key column, so MySQL keeps the index it made for the constraint and `down()` can drop these.
     */
    public const WANTED = [
        // The latest posts on the forum index, across every thread.
        ['forum_posts', ['created_at'], 'forum_posts_created_at_index'],
        // The approved comments of a post (its list, its count), and the count behind "most commented" for each post.
        ['blog_comments', ['status', 'blog_id'], 'blog_comments_status_blog_id_index'],
        // A board's threads, pinned first and then the latest reply. The page also sorts by when the thread began, so that is in the
        // index too: without it MySQL and MariaDB fall back on the board's own index and sort every thread of the board.
        ['forum_threads', ['is_published', 'forum_board_id', 'is_pinned', 'last_posted_at', 'created_at'], 'forum_threads_board_order_index'],
        // The member list: the newest first, without the banned.
        ['users', ['is_banned', 'created_at'], 'users_is_banned_created_at_index'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable(self::REGISTRY)) {
            Schema::create(self::REGISTRY, function (Blueprint $table) {
                // PostgreSQL keeps 63 bytes of a name, MySQL 64.
                $table->string('table_name', 63);
                $table->string('index_name', 63);

                $table->primary(['table_name', 'index_name']);
            });
        }

        foreach (self::WANTED as [$table, $columns, $name]) {
            if ($this->startsAnIndex($columns, Schema::getIndexes($table))) {
                continue;
            }

            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));

            DB::table(self::REGISTRY)->insertOrIgnore(['table_name' => $table, 'index_name' => $name]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::REGISTRY)) {
            return;
        }

        foreach (DB::table(self::REGISTRY)->get() as $made) {
            // Not there any more if somebody took the index away by hand.
            if (in_array($made->index_name, array_column(Schema::getIndexes($made->table_name), 'name'), true)) {
                Schema::table($made->table_name, fn (Blueprint $blueprint) => $blueprint->dropIndex($made->index_name));
            }
        }

        Schema::drop(self::REGISTRY);
    }

    /**
     * @param  list<string>  $columns
     * @param  list<array{name: string, columns: list<string>}>  $indexes
     */
    private function startsAnIndex(array $columns, array $indexes): bool
    {
        foreach ($indexes as $index) {
            if (array_slice($index['columns'], 0, count($columns)) === $columns) {
                return true;
            }
        }

        return false;
    }
};
