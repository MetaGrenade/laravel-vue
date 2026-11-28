<?php

namespace App\Support\Database;

use Carbon\CarbonInterface;

/**
 * The dates the site can keep in the columns it uses for most times, on every database it runs on.
 *
 * MySQL and MariaDB hold only 1970-01-01 00:00:01 to 2038-01-19 03:14:07 (UTC) in a `timestamp` column and refuse the rest, PostgreSQL
 * refuses a year before 1, and the site cannot read back a year of more than four digits whatever the database kept. The range here is whole
 * years inside all of that, so a time at either end of it still fits after it is turned from one time zone into another.
 */
final class TimestampRange
{
    public const FIRST_YEAR = 1971;

    public const LAST_YEAR = 2037;

    public static function holds(CarbonInterface $moment): bool
    {
        return $moment->year >= self::FIRST_YEAR && $moment->year <= self::LAST_YEAR;
    }

    /**
     * The moment itself, or the end of the range that it is past. For a list filtered by date: nothing the site keeps lies outside the
     * range, so the end of it matches the same rows as the date that was asked for.
     *
     * @template TMoment of CarbonInterface
     *
     * @param  TMoment  $moment
     * @return TMoment
     */
    public static function nearest(CarbonInterface $moment): CarbonInterface
    {
        if (self::holds($moment)) {
            return $moment;
        }

        return $moment->year < self::FIRST_YEAR
            ? $moment->copy()->setDateTime(self::FIRST_YEAR, 1, 1, 0, 0, 0)
            : $moment->copy()->setDateTime(self::LAST_YEAR, 12, 31, 23, 59, 59);
    }
}
