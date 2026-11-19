<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Deletes the rows of a database cache that have run out, so that the table does not grow for ever.
 *
 * Laravel's database cache store deletes an expired row only when that very key is asked for again. A key nobody asks for again stays: the
 * counters of the rate limiters (two rows for every failed sign-in, whatever address was tried, and for every address that asks for a
 * password reset), a page's cached counts, a search that was made once. Redis drops them by itself; the database does not, and nothing
 * else in the framework does either. A row that has run out is no longer an answer (`get()` treats it as missing, `add()` replaces it),
 * so deleting it changes nothing a request can see.
 *
 * Every store whose driver is `database` is cleaned, each table once, whatever it is called and whichever connection it is on. A site on
 * Redis or files has none and the job does nothing.
 */
class PruneExpiredCacheEntries implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Rows are deleted this many at a time, so that a table with a great many does not hold a lock for the whole of it.
     *
     * It is also the number of keys the delete binds (`whereIn` binds one parameter for each, and the `expiration` test one more), and SQL
     * Server refuses a statement of more than 2,100 parameters (`sqlsrv` is a connection of config/database.php), so it stays well below
     * that: a backlog bigger than the limit would otherwise fail before the first chunk, and fail the same way on every retry.
     */
    private const CHUNK = 1000;

    public function handle(): void
    {
        $now = now()->getTimestamp();

        foreach ($this->tables() as [$connection, $table]) {
            do {
                // The cache's own primary key is the key, so the rows are found by it and taken by it: a delete with a limit is written
                // differently by each database, and a key list is the same on all of them.
                $keys = DB::connection($connection)->table($table)->where('expiration', '<=', $now)->limit(self::CHUNK)->pluck('key')->all();

                $deleted = $keys === [] ? 0 : DB::connection($connection)->table($table)->whereIn('key', $keys)->where('expiration', '<=', $now)->delete();
            } while ($deleted > 0);
        }
    }

    /**
     * The tables of the database stores, each once.
     *
     * @return list<array{0: string|null, 1: string}>
     */
    private function tables(): array
    {
        $tables = [];

        foreach ((array) config('cache.stores', []) as $store) {
            if (! is_array($store) || ($store['driver'] ?? null) !== 'database') {
                continue;
            }

            $connection = isset($store['connection']) && is_string($store['connection']) ? $store['connection'] : null;
            $table = is_string($store['table'] ?? null) ? $store['table'] : 'cache';

            $tables[($connection ?? '').'|'.$table] = [$connection, $table];
        }

        return array_values($tables);
    }
}
