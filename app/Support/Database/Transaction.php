<?php

namespace App\Support\Database;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;

class Transaction
{
    /**
     * Execute the given callback within a transaction when supported.
     */
    public static function run(Closure $callback, ConnectionInterface|DatabaseManager|null $connection = null): mixed
    {
        $connection ??= DB::connection();

        if ($connection instanceof DatabaseManager) {
            $connection = $connection->connection();
        }

        if ($connection instanceof SQLiteConnection && app()->environment('testing')) {
            return $callback();
        }

        return $connection->transaction($callback);
    }
}
