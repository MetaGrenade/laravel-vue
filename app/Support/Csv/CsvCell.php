<?php

namespace App\Support\Csv;

/**
 * A spreadsheet runs a cell that starts with `=`, `+`, `-` or `@` as a formula, and a CSV that staff open in Excel, LibreOffice or Sheets is a
 * spreadsheet. A visitor chooses the words of every search that is logged (`=HYPERLINK(...)` or `=cmd|...` are searches like any other), so a file
 * made from them must not carry one through as a formula (CWE-1236). A leading tab or carriage return can hide a formula the same way.
 *
 * Such a cell gets a leading apostrophe, which makes it text (Excel does not show it). Everything else is written as it is.
 */
final class CsvCell
{
    private const TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    public static function text(?string $value): string
    {
        $value ??= '';

        if ($value !== '' && in_array($value[0], self::TRIGGERS, true)) {
            return "'".$value;
        }

        return $value;
    }
}
