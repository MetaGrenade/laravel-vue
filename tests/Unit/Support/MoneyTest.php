<?php

namespace Tests\Unit\Support;

use App\Support\Commerce\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    /**
     * @return array<string, array{string|int|float, string, int}>
     */
    public static function parsing(): array
    {
        return [
            'whole dollars' => ['19', 'USD', 1900],
            'two decimals' => ['19.99', 'USD', 1999],
            'one decimal' => ['19.5', 'USD', 1950],
            'database decimal' => ['0.07', 'USD', 7],
            'rounds half up' => ['1.005', 'USD', 101],
            'rounds down' => ['1.004', 'USD', 100],
            'negative' => ['-5.25', 'USD', -525],
            'float that is not exact in binary' => [19.99, 'USD', 1999],
            'float 0.1 + 0.2' => [0.1 + 0.2, 'USD', 30],
            'integer' => [7, 'USD', 700],
            'null is zero' => [0, 'USD', 0],
            'zero decimal currency' => ['1500', 'JPY', 1500],
            'zero decimal currency stored with decimals' => ['1500.00', 'JPY', 1500],
            'lowercase currency' => ['2.50', 'eur', 250],
        ];
    }

    #[Test]
    #[DataProvider('parsing')]
    public function it_parses_decimal_amounts_into_minor_units(string|int|float $amount, string $currency, int $minor): void
    {
        $this->assertSame($minor, Money::parse($amount, $currency)->minor);
    }

    #[Test]
    public function it_formats_back_to_the_decimal_stored_in_price_columns(): void
    {
        $this->assertSame('19.99', Money::ofMinor(1999, 'USD')->toDecimal());
        $this->assertSame('0.07', Money::ofMinor(7, 'USD')->toDecimal());
        $this->assertSame('0.00', Money::zero('USD')->toDecimal());
        $this->assertSame('-5.25', Money::ofMinor(-525, 'USD')->toDecimal());
        $this->assertSame('1500.00', Money::ofMinor(1500, 'JPY')->toDecimal());
    }

    #[Test]
    public function arithmetic_is_exact(): void
    {
        // 0.1 + 0.2 is the classic float trap.
        $sum = Money::parse('0.10', 'USD')->add(Money::parse('0.20', 'USD'));

        $this->assertSame('0.30', $sum->toDecimal());
        $this->assertSame('59.97', Money::parse('19.99', 'USD')->multiply(3)->toDecimal());
        $this->assertSame('4.00', Money::parse('5.00', 'USD')->subtract(Money::parse('1.00', 'USD'))->toDecimal());
        $this->assertTrue(Money::parse('0.30', 'USD')->equals($sum));
    }

    #[Test]
    public function it_refuses_to_mix_currencies(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::parse('1', 'USD')->add(Money::parse('1', 'EUR'));
    }

    #[Test]
    public function it_rejects_malformed_amounts_and_currencies(): void
    {
        foreach (['abc', '1,5', '1.2.3', '--1'] as $bad) {
            try {
                Money::parse($bad, 'USD');
                $this->fail("{$bad} should be rejected");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(InvalidArgumentException::class);
        Money::parse('1', 'US');
    }

    #[Test]
    public function three_decimal_currencies_are_not_supported(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::parse('1.000', 'KWD');
    }

    /**
     * @return array<string, array{int, string, int}>
     */
    public static function percentages(): array
    {
        return [
            'twenty percent of 19.99' => [1999, '20', 400],
            'a fractional rate rounds half up' => [1000, '8.875', 89],
            'half a minor unit rounds up' => [10, '5', 1],
            'just under half rounds down' => [9, '5', 0],
            'zero percent' => [1999, '0', 0],
            'one hundred percent' => [1999, '100', 1999],
            'four decimals' => [100000, '7.7777', 7778],
            'negative amounts round away from zero' => [-1000, '8.875', -89],
            'a very large amount stays exact' => [9_999_999_999, '100', 9_999_999_999],
        ];
    }

    #[Test]
    #[DataProvider('percentages')]
    public function it_applies_a_percentage_exactly(int $minor, string $percent, int $expected): void
    {
        $this->assertSame($expected, Money::ofMinor($minor, 'USD')->percent($percent)->minor);
    }

    #[Test]
    public function it_rejects_malformed_percentages(): void
    {
        foreach (['-5', 'abc', '1.23456', '', '5%', '1,5'] as $bad) {
            try {
                Money::ofMinor(100, 'USD')->percent($bad);
                $this->fail("'{$bad}' should be rejected");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function allocation_hands_the_odd_cent_to_the_first_of_equal_parts(): void
    {
        $parts = Money::ofMinor(100, 'USD')->allocate([1, 1, 1]);

        $this->assertSame([34, 33, 33], array_map(fn (Money $part) => $part->minor, $parts));
    }

    #[Test]
    public function allocation_follows_the_weights(): void
    {
        $this->assertSame([3, 3, 4], array_map(fn (Money $part) => $part->minor, Money::ofMinor(10, 'USD')->allocate([3, 3, 4])));
        $this->assertSame([0, 100, 0], array_map(fn (Money $part) => $part->minor, Money::ofMinor(100, 'USD')->allocate([0, 5, 0])));
        $this->assertSame([1, 0], array_map(fn (Money $part) => $part->minor, Money::ofMinor(1, 'USD')->allocate([1, 1])));
    }

    #[Test]
    public function allocation_of_a_negative_amount_mirrors_the_positive(): void
    {
        $this->assertSame([-34, -33, -33], array_map(fn (Money $part) => $part->minor, Money::ofMinor(-100, 'USD')->allocate([1, 1, 1])));
    }

    #[Test]
    public function allocation_with_no_weight_gives_everything_to_the_first_part(): void
    {
        $this->assertSame([57, 0, 0], array_map(fn (Money $part) => $part->minor, Money::ofMinor(57, 'USD')->allocate([0, 0, 0])));
    }

    #[Test]
    public function allocated_parts_always_add_up_to_the_whole(): void
    {
        mt_srand(20261007);

        for ($round = 0; $round < 500; $round++) {
            $weights = array_map(fn () => mt_rand(0, 100_000), range(1, mt_rand(1, 9)));
            $amount = mt_rand(-1_000_000, 1_000_000);

            $parts = Money::ofMinor($amount, 'USD')->allocate($weights);

            $this->assertCount(count($weights), $parts);
            $this->assertSame($amount, array_sum(array_map(fn (Money $part) => $part->minor, $parts)), 'weights: '.implode(',', $weights));
        }
    }

    #[Test]
    public function allocation_rejects_nonsense(): void
    {
        foreach ([[], [1, -1]] as $weights) {
            try {
                Money::ofMinor(100, 'USD')->allocate($weights);
                $this->fail('expected an exception');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
