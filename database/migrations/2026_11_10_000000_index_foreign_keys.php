<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An index for every foreign key that has none (docs/indexes.md).
 *
 * MySQL and MariaDB index a foreign key column when the constraint is made. PostgreSQL and SQLite do not, and the migrations only
 * declared the constraints, so on those two a thread page read every post on the site to find its own, and deleting a member checked
 * every table that points at them with a scan. Nothing is done on MySQL or MariaDB, and elsewhere nothing for a column that already
 * starts an index, so it is safe to run again.
 *
 * Which indexes are this migration's is written down, in the `foreign_key_indexes` table, as each one is made, and `down()` takes
 * back exactly those. It never goes by a name: an index somebody made before (a unique key included) can be called anything,
 * `..._fk` and the very name this migration would give included, and it is theirs.
 */
return new class extends Migration
{
    /** The indexes this migration made. */
    public const REGISTRY = 'foreign_key_indexes';

    /** PostgreSQL cuts an identifier at 63 bytes (MySQL allows 64), and two cut names that start alike would collide. */
    private const LONGEST = 63;

    private const SUFFIX = '_fk';

    public function up(): void
    {
        if ($this->indexesItself()) {
            return;
        }

        if (! Schema::hasTable(self::REGISTRY)) {
            Schema::create(self::REGISTRY, function (Blueprint $table) {
                $table->string('table_name');
                $table->string('index_name');

                $table->primary(['table_name', 'index_name']);
            });
        }

        foreach (Schema::getTables() as $table) {
            $table = $table['name'];
            $indexes = Schema::getIndexes($table);

            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                $columns = $foreignKey['columns'];

                if ($this->startsAnIndex($columns, $indexes)) {
                    continue;
                }

                $name = $this->nameOf($table, $columns);

                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));

                DB::table(self::REGISTRY)->insertOrIgnore(['table_name' => $table, 'index_name' => $name]);

                // Two foreign keys on the same columns would otherwise try to make the same index.
                $indexes[] = ['name' => $name, 'columns' => $columns];
            }
        }
    }

    public function down(): void
    {
        if ($this->indexesItself() || ! Schema::hasTable(self::REGISTRY)) {
            return;
        }

        foreach (DB::table(self::REGISTRY)->get() as $made) {
            // Not there any more if the table was dropped (it has no indexes then) or somebody took the index away by hand.
            if (in_array($made->index_name, array_column(Schema::getIndexes($made->table_name), 'name'), true)) {
                Schema::table($made->table_name, fn (Blueprint $blueprint) => $blueprint->dropIndex($made->index_name));
            }
        }

        Schema::drop(self::REGISTRY);
    }

    /**
     * InnoDB will not make a foreign key without an index on the column, and makes one when there is none. Looking for the missing
     * ones there would only ask information_schema about every table (slow) to find none.
     */
    private function indexesItself(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
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

    /**
     * `forum_posts_forum_thread_id_fk`. A name too long for PostgreSQL keeps its start, a hash of the whole name and the suffix, so
     * it is the same each time it is made. The suffix only tells a reader what the index is for; it is not how `down()` finds them.
     *
     * @param  list<string>  $columns
     */
    private function nameOf(string $table, array $columns): string
    {
        $stem = $table.'_'.implode('_', $columns);

        if (strlen($stem.self::SUFFIX) <= self::LONGEST) {
            return $stem.self::SUFFIX;
        }

        return substr($stem, 0, self::LONGEST - strlen(self::SUFFIX) - 9).'_'.substr(sha1($stem), 0, 8).self::SUFFIX;
    }
};
