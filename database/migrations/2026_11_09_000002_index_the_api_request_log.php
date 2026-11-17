<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two indexes for the log of requests made with an API key (docs/indexes.md).
 *
 * `token_logs` had only the key of the API key it belongs to, and a row is added for every request made with one. Three things read it
 * by when a row was made, and none of them could use that index to do it:
 *
 *  - A key with an hourly or a daily limit counts its rows of the last hour and the last day before every request it makes: a count of
 *    one key's rows since a time. It read the whole history of the key, and with one key making 40% of a million rows MariaDB skipped
 *    the index and read the table: 130 ms for each limit, on every request.
 *  - Admin → Tokens counts the last hour and the last day for every key at once, lists the newest rows, and pages them.
 *  - The daily PruneTokenLogs job deletes what is older than the retention.
 *
 * `(personal_access_token_id, created_at)` is the first, and the lookup of when a limit lets the key through again (the oldest row of the
 * window). `(created_at, personal_access_token_id)` is the other two: the newest rows are its end, a time range is a stretch of it, and
 * it holds the key of each row, so counting per key never reads the table.
 *
 * The first starts with the foreign key's column. MySQL and MariaDB keep an index of their own for a foreign key and drop it when another
 * one that starts with the same column is made, and then the constraint stands on the new one and `down()` cannot drop it (error 1553).
 * So `down()` takes the constraint off first and puts it back after, which gives it an index of its own again.
 *
 * Its date is the one just before `index_foreign_keys` on purpose. PostgreSQL and SQLite do not index a foreign key, and that migration
 * makes an index for every column that starts none; run after this one, it finds the foreign key's column already starts one and makes
 * nothing for `token_logs`, so a new install has no second index on the same column. (A database that has already run it keeps the one
 * it made, which is harmless: it is that migration's, and its `down()` takes it back.)
 */
return new class extends Migration
{
    private const TABLE = 'token_logs';

    /** A name Laravel would not give, so that an index a person made under the usual one is theirs and `down()` leaves it. */
    private const BY_KEY = 'token_logs_by_key_and_time';

    private const BY_TIME = 'token_logs_by_time_and_key';

    public function up(): void
    {
        $indexes = Schema::getIndexes(self::TABLE);

        Schema::table(self::TABLE, function (Blueprint $table) use ($indexes) {
            if (! $this->startsAnIndex(['personal_access_token_id', 'created_at'], $indexes)) {
                $table->index(['personal_access_token_id', 'created_at'], self::BY_KEY);
            }

            if (! $this->startsAnIndex(['created_at', 'personal_access_token_id'], $indexes)) {
                $table->index(['created_at', 'personal_access_token_id'], self::BY_TIME);
            }
        });
    }

    public function down(): void
    {
        $names = array_column(Schema::getIndexes(self::TABLE), 'name');

        if (in_array(self::BY_KEY, $names, true)) {
            $this->dropIndexThatTheForeignKeyMayStandOn(self::BY_KEY);
            $this->keepTheForeignKeyIndexed();
        }

        if (in_array(self::BY_TIME, $names, true)) {
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropIndex(self::BY_TIME));
        }
    }

    /**
     * On PostgreSQL and SQLite the foreign key's column may have had no index but the first one (see the note above), and then the column
     * is left with none, which is what `index_foreign_keys` exists to prevent. Gives it the one that migration would have given, under
     * its name, and writes it in its list so that its `down()` takes it back. MySQL and MariaDB make one themselves.
     */
    private function keepTheForeignKeyIndexed(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) || $this->startsAnIndex(['personal_access_token_id'], Schema::getIndexes(self::TABLE))) {
            return;
        }

        $name = 'token_logs_personal_access_token_id_fk';

        Schema::table(self::TABLE, fn (Blueprint $table) => $table->index('personal_access_token_id', $name));

        if (Schema::hasTable('foreign_key_indexes')) {
            DB::table('foreign_key_indexes')->insertOrIgnore(['table_name' => self::TABLE, 'index_name' => $name]);
        }
    }

    private function dropIndexThatTheForeignKeyMayStandOn(string $name): void
    {
        $holdsTheConstraint = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) && $this->hasForeignKey();

        if (! $holdsTheConstraint) {
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropIndex($name));

            return;
        }

        Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropForeign(['personal_access_token_id']));

        Schema::table(self::TABLE, function (Blueprint $table) use ($name) {
            $table->dropIndex($name);
            $table->foreign('personal_access_token_id')->references('id')->on('personal_access_tokens')->cascadeOnDelete();
        });
    }

    private function hasForeignKey(): bool
    {
        foreach (Schema::getForeignKeys(self::TABLE) as $foreignKey) {
            if ($foreignKey['columns'] === ['personal_access_token_id']) {
                return true;
            }
        }

        return false;
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
