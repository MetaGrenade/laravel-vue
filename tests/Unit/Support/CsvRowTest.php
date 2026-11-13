<?php

namespace Tests\Unit\Support;

use App\Support\Csv\CsvRow;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * One line of a CSV as RFC 4180 has it: a quote is doubled, there is no backslash escape, and a line ends with `\n`.
 */
class CsvRowTest extends TestCase
{
    /**
     * @param  array<int, string|int|float|null>  $cells
     */
    private function written(array $cells): string
    {
        $handle = fopen('php://temp', 'r+');
        CsvRow::write($handle, $cells);
        rewind($handle);
        $text = (string) stream_get_contents($handle);
        fclose($handle);

        return $text;
    }

    #[Test]
    public function plain_cells_are_written_as_they_are_and_a_null_is_empty(): void
    {
        $this->assertSame("a,12,3.5,,bc\n", $this->written(['a', 12, 3.5, null, 'bc']));
    }

    #[Test]
    public function a_cell_with_a_space_in_it_is_put_in_quotes_as_it_always_was(): void
    {
        $this->assertSame("\"b c\",d\n", $this->written(['b c', 'd']));
    }

    #[Test]
    public function a_quote_is_doubled_and_a_backslash_before_it_changes_nothing(): void
    {
        // PHP's default escape character would write the quote after the backslash undoubled: "a\"b".
        $this->assertSame("\"a\\\"\"b\",x\n", $this->written(['a\\"b', 'x']));
        $this->assertSame("\"say \"\"hi\"\"\"\n", $this->written(['say "hi"']));
    }

    #[Test]
    public function a_comma_and_a_line_break_put_the_cell_in_quotes(): void
    {
        $this->assertSame("\"a,b\",\"c\nd\"\n", $this->written(['a,b', "c\nd"]));
    }

    #[Test]
    public function what_is_written_is_read_back_as_it_was(): void
    {
        $cells = ['a\\"b', 'C:\\temp\\', "line\r\nbreak", 'it\'s, "quoted"', ''];

        $handle = fopen('php://temp', 'r+');
        CsvRow::write($handle, $cells);
        rewind($handle);

        $this->assertSame($cells, fgetcsv($handle, null, ',', '"', ''));

        fclose($handle);
    }
}
