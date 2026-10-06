<?php

namespace App\Support\Database;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/**
 * Small helpers for SQL expressions that differ between database drivers.
 */
class Sql
{
    /**
     * A `YYYY-MM` string for the given date/time column.
     */
    public static function yearMonth(string $column, ?string $alias = null, ?string $connection = null): Expression
    {
        $grammar = DB::connection($connection)->getQueryGrammar();
        $wrapped = $grammar->wrap($column);

        $sql = match (DB::connection($connection)->getDriverName()) {
            'pgsql' => "to_char({$wrapped}, 'YYYY-MM')",
            'sqlite' => "strftime('%Y-%m', {$wrapped})",
            'sqlsrv' => "format({$wrapped}, 'yyyy-MM')",
            default => "date_format({$wrapped}, '%Y-%m')",
        };

        return DB::raw($alias ? "{$sql} as {$grammar->wrap($alias)}" : $sql);
    }
}
