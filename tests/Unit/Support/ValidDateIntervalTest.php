<?php

namespace Tests\Unit\Support;

use App\Rules\ValidDateInterval;
use DateInterval;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The rule that reads a time written as text ("2 days") for the support SLA settings, on its own. Since PHP 8.3 an unreadable text makes
 * `DateInterval::createFromDateString()` throw instead of returning false; the rule has to turn that into a refusal.
 */
class ValidDateIntervalTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function readable(): array
    {
        return ['days' => ['2 days'], 'hours' => ['48 hours'], 'minutes' => ['90 minutes'], 'with a plus' => ['+1 week']];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unreadable(): array
    {
        return ['a word' => ['banana'], 'nonsense' => ['soon-ish'], 'a unit with no number' => ['xyz hours']];
    }

    private function refusals(mixed $value): int
    {
        $refused = 0;

        (new ValidDateInterval)->validate('after', $value, function () use (&$refused): void {
            $refused++;
        });

        return $refused;
    }

    #[Test]
    #[DataProvider('readable')]
    public function a_time_that_can_be_read_is_accepted_and_parsed(string $text): void
    {
        $this->assertSame(0, $this->refusals($text));
        $this->assertInstanceOf(DateInterval::class, ValidDateInterval::parse($text));
    }

    #[Test]
    #[DataProvider('unreadable')]
    public function text_that_is_not_a_time_is_refused_not_thrown(string $text): void
    {
        $this->assertSame(1, $this->refusals($text));
        $this->assertNull(ValidDateInterval::parse($text));
    }

    #[Test]
    public function nothing_at_all_is_left_to_the_rules_that_ask_for_it(): void
    {
        $this->assertSame(0, $this->refusals(null));
        $this->assertSame(0, $this->refusals(''));
    }
}
