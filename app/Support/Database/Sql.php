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

    /**
     * How many seconds `$to` is after `$from` (negative when it is before), for two date/time columns, so that an average over thousands of rows
     * is worked out by the database instead of by reading every row into PHP. Whole seconds: the columns have none finer.
     */
    public static function secondsBetween(string $from, string $to, ?string $alias = null, ?string $connection = null): Expression
    {
        $grammar = DB::connection($connection)->getQueryGrammar();
        $start = $grammar->wrap($from);
        $end = $grammar->wrap($to);

        $sql = match (DB::connection($connection)->getDriverName()) {
            'pgsql' => "extract(epoch from ({$end} - {$start}))",
            'sqlite' => "(cast(strftime('%s', {$end}) as integer) - cast(strftime('%s', {$start}) as integer))",
            'sqlsrv' => "datediff(second, {$start}, {$end})",
            default => "timestampdiff(second, {$start}, {$end})",
        };

        return DB::raw($alias ? "{$sql} as {$grammar->wrap($alias)}" : $sql);
    }
}
