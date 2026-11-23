<?php

namespace Tests\Unit\Config;

use App\Support\Config\EnvNumber;
use Illuminate\Support\Env;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What a number read from the environment is for each way a line of `.env` can be written (App\Support\Config\EnvNumber). A blank
 * line is the default, not 0.
 */
class EnvNumberTest extends TestCase
{
    private const KEY = 'ENV_NUMBER_TEST_SETTING';

    /**
     * @param  string|null  $line  what the setting is set to; null leaves it out of the environment
     */
    private function read(?string $line, callable $read): mixed
    {
        $repository = Env::getRepository();
        $repository->clear(self::KEY);

        if ($line !== null) {
            $repository->set(self::KEY, $line);
        }

        try {
            return $read();
        } finally {
            $repository->clear(self::KEY);
        }
    }

    /**
     * @return array<string, array{string|null, int}> the line, and the whole number read with a default of 5
     */
    public static function wholeNumbers(): array
    {
        return [
            'not there: the default' => [null, 5],
            'empty (`KEY=`): the default, not 0' => ['', 5],
            'only spaces: the default' => ['   ', 5],
            'the word null: the default' => ['null', 5],
            'true: the default' => ['true', 5],
            'false: the default' => ['false', 5],
            'words: the default, not 0' => ['soon', 5],
            'a number with a unit: the default' => ['15s', 5],
            '0 is 0: whoever means it writes it' => ['0', 0],
            'a number' => ['12', 12],
            'a number with a zero in front' => ['012', 12],
            'a negative number stays negative' => ['-3', -3],
            'a fraction is cut' => ['2.5', 2],
            'a number with a space after it' => ['7 ', 7],
        ];
    }

    #[Test]
    #[DataProvider('wholeNumbers')]
    public function a_whole_number_is_the_default_unless_the_line_is_a_number(?string $line, int $expected): void
    {
        $this->assertSame($expected, $this->read($line, fn () => EnvNumber::integer(self::KEY, 5)));
    }

    /**
     * @return array<string, array{string|null, float}> the line, and the number read with a default of 1.5
     */
    public static function fractions(): array
    {
        return [
            'not there: the default' => [null, 1.5],
            'empty: the default, not 0' => ['', 1.5],
            'words: the default' => ['soon', 1.5],
            '0 is 0' => ['0', 0.0],
            'a whole number' => ['3', 3.0],
            'a fraction' => ['2.25', 2.25],
            'a negative number stays negative' => ['-1.5', -1.5],
        ];
    }

    #[Test]
    #[DataProvider('fractions')]
    public function a_number_with_a_fraction_is_the_default_unless_the_line_is_a_number(?string $line, float $expected): void
    {
        $this->assertSame($expected, $this->read($line, fn () => EnvNumber::float(self::KEY, 1.5)));
    }

    /**
     * @return array<string, array{string|null, int}> the line, and the whole number read with a default of 5 and an empty line meaning 0
     */
    public static function settingsWithAMeaningForAnEmptyLine(): array
    {
        return [
            'not there: the default' => [null, 5],
            'empty: what the setting says it is, not the default' => ['', 0],
            'only spaces: the same' => ['   ', 0],
            'words are not an empty line: the default' => ['soon', 5],
            'the word null: the default' => ['null', 5],
            '0 is 0' => ['0', 0],
            'a number' => ['12', 12],
            'a negative number stays negative' => ['-3', -3],
        ];
    }

    #[Test]
    #[DataProvider('settingsWithAMeaningForAnEmptyLine')]
    public function a_setting_can_say_what_an_empty_line_means_and_only_an_empty_line(?string $line, int $expected): void
    {
        $this->assertSame($expected, $this->read($line, fn () => EnvNumber::integer(self::KEY, 5, whenBlank: 0)));
    }

    #[Test]
    public function an_empty_line_can_mean_something_other_than_zero(): void
    {
        $this->assertSame(7, $this->read('', fn () => EnvNumber::integer(self::KEY, 5, whenBlank: 7)));
        $this->assertSame(-1, $this->read('', fn () => EnvNumber::integer(self::KEY, 5, whenBlank: -1)));
    }

    #[Test]
    public function the_default_is_given_back_as_it_was_written_in_the_file_that_asks(): void
    {
        $this->assertSame(0, EnvNumber::integer('ENV_NUMBER_TEST_NOT_SET', 0));
        $this->assertSame(-1, EnvNumber::integer('ENV_NUMBER_TEST_NOT_SET', -1));
        $this->assertSame(16000000, EnvNumber::integer('ENV_NUMBER_TEST_NOT_SET', 16_000_000));
    }
}
