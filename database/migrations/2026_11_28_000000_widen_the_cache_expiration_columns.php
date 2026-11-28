<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Room in the database cache for a time after January 2038.
 *
 * `cache.expiration` and `cache_locks.expiration` hold the moment a row runs out, as a Unix time, and were 32-bit integers, which end at
 * 03:14:07 UTC on 19 January 2038. An entry kept "forever" is written to run out ten years from now (`DatabaseStore::forever()`), so
 * MySQL, MariaDB and PostgreSQL would have refused every one of them from 22 January 2028: `queue:restart`, which every deploy runs,
 * `schedule:pause`, `queue:pause`, and the support assignment rules, without which no support ticket can be opened. SQLite keeps any
 * 64-bit number in any integer column and is not affected. The columns are 64-bit now, as Laravel's own cache migration makes them.
 *
 * These are the two tables the first migration makes. A table a site points `DB_CACHE_TABLE` at is its own to widen (docs/upgrading.md).
 */
return new class extends Migration
{
    private const TABLES = ['cache', 'cache_locks'];

    /** The latest time a 32-bit integer holds. */
    private const LARGEST_SMALL = 2147483647;

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->bigInteger('expiration')->change());
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            // What does not fit would stop the change. It is a cache, so a row that has to go is made again when it is next wanted.
            DB::table($name)->where('expiration', '>', self::LARGEST_SMALL)->delete();

            Schema::table($name, fn (Blueprint $table) => $table->integer('expiration')->change());
        }
    }
};
