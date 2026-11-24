<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An index for the bell in the header (docs/indexes.md).
 *
 * Every page a signed-in member opens asks the `notifications` table two questions about that member: how many they have not read
 * (`read_at IS NULL`), and the newest ten of those (`ORDER BY created_at DESC LIMIT 10`; `HandleInertiaRequests`). The only index was
 * the one Laravel makes for `notifiable_type, notifiable_id`, which finds the member's rows and nothing more: both questions read every
 * notification the member has ever had, the read ones as well (nothing deletes them, and nothing lists them, once read they are only
 * in the way), and the second sorts them all to take ten.
 *
 * `(notifiable_type, notifiable_id, read_at, created_at)` answers both from the index: the member and `read_at IS NULL` are equalities
 * (to MariaDB and SQLite, which were measured), so the unread ones are one stretch of the index, already in the order of `created_at`.
 * A member who reads what they get pays the same however long they have been a member, and one who never does pays for counting the
 * unread ones and not for sorting them. PostgreSQL may not take `IS NULL` for an equality when it looks for an order, and then it sorts
 * the member's unread rows (never the read ones); docs/indexes.md says what to try if that shows.
 *
 * The name is one Laravel would not give (what it would give is 68 bytes, and PostgreSQL keeps 63), so an index somebody made before
 * is theirs: when one starts with these columns, in this order, under any name, this migration makes none, and `down()` drops only
 * the name it gave. The index Laravel made for the first two columns is left where it is: it is the framework's own and a prefix of
 * this one, which only costs the table a little on every write.
 */
return new class extends Migration
{
    public const INDEX = 'notifications_unread_by_time';

    public const COLUMNS = ['notifiable_type', 'notifiable_id', 'read_at', 'created_at'];

    private const TABLE = 'notifications';

    public function up(): void
    {
        if ($this->startsAnIndex(self::COLUMNS, Schema::getIndexes(self::TABLE))) {
            return;
        }

        Schema::table(self::TABLE, fn (Blueprint $table) => $table->index(self::COLUMNS, self::INDEX));
    }

    public function down(): void
    {
        // Not there any more if somebody took it away by hand.
        if (in_array(self::INDEX, array_column(Schema::getIndexes(self::TABLE), 'name'), true)) {
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropIndex(self::INDEX));
        }
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
