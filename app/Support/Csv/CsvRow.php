<?php

namespace App\Support\Csv;

/**
 * One line of a CSV, written the way RFC 4180 has it. Every CSV the app makes goes through here.
 *
 * The escape character is switched off (`''`): RFC 4180 doubles a quote and has no backslash escape, and with PHP's default a cell that has a backslash
 * before a quote (`a\"b`) is written with the quote NOT doubled, so a reader ends the cell there and puts the rest in the next one. PHP 8.4 also
 * deprecates leaving the argument out. The line ends are `\n` whatever the platform.
 *
 * What a cell may start with is a separate question, for the cells that somebody else typed: see `CsvCell`.
 */
final class CsvRow
{
    /**
     * @param  resource  $handle
     * @param  array<int, string|int|float|null>  $cells
     */
    public static function write($handle, array $cells): void
    {
        fputcsv($handle, $cells, ',', '"', '', "\n");
    }
}
