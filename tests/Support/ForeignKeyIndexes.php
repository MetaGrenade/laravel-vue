<?php

namespace Tests\Support;

/**
 * The foreign key indexes (database/migrations/2026_11_10_000000_index_foreign_keys.php), taken off and put back.
 *
 * A migration test that rolls an older migration back by hand has to do what `migrate:rollback` does: the newer migration comes off
 * first. SQLite refuses to drop a column an index uses, so rolling back a migration that adds a foreign key column fails while the
 * index on that column is there. Put `takeOff()` before the older `down()` calls and `putBack()` after the older `up()` calls.
 */
final class ForeignKeyIndexes
{
    public static function takeOff(): void
    {
        self::migration()->down();
    }

    public static function putBack(): void
    {
        self::migration()->up();
    }

    public static function migration(): object
    {
        return require database_path('migrations/2026_11_10_000000_index_foreign_keys.php');
    }
}
