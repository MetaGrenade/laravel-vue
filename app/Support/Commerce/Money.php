<?php

namespace App\Support\Commerce;

use InvalidArgumentException;

/**
 * An amount of money held as an integer number of minor units (cents).
 *
 * Prices are stored as decimal columns but all arithmetic happens here on
 * integers, so totals never pick up floating point error and the amount sent
 * to a payment provider is exact.
 */
final readonly class Money
{
    /**
     * Currencies that have no minor unit: 1 unit is the smallest amount.
     *
     * @var list<string>
     */
    private const ZERO_DECIMAL = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
        'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    /**
     * Three-decimal currencies cannot be stored in the two-decimal price columns.
     *
     * @var list<string>
     */
    private const UNSUPPORTED = ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'];

    private function __construct(
        public int $minor,
        public string $currency,
    ) {}

    public static function ofMinor(int $minor, string $currency): self
    {
        return new self($minor, self::normaliseCurrency($currency));
    }

    public static function zero(string $currency): self
    {
        return self::ofMinor(0, $currency);
    }

    /**
     * Parse a decimal amount such as "19.99" (the format the database returns).
     * Extra digits are rounded half up.
     */
    public static function parse(string|int|float|null $amount, string $currency): self
    {
        $currency = self::normaliseCurrency($currency);
        $exponent = self::exponentFor($currency);

        if ($amount === null || $amount === '') {
            return new self(0, $currency);
        }

        $string = is_float($amount)
            ? rtrim(rtrim(number_format($amount, 8, '.', ''), '0'), '.')
            : trim((string) $amount);

        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $string, $matches)) {
            throw new InvalidArgumentException("'{$string}' is not a valid money amount.");
        }

        $negative = $matches[1] === '-';
        $fraction = $matches[3] ?? '';

        $minor = ((int) $matches[2]) * (10 ** $exponent);

        if ($exponent > 0) {
            $minor += (int) substr(str_pad($fraction, $exponent, '0'), 0, $exponent);
        }

        // Round half up on the first discarded digit.
        if (strlen($fraction) > $exponent && (int) $fraction[$exponent] >= 5) {
            $minor++;
        }

        return new self($negative ? -$minor : $minor, $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor - $other->minor, $this->currency);
    }

    public function multiply(int $factor): self
    {
        return new self($this->minor * $factor, $this->currency);
    }

    /**
     * This amount times a percentage given with up to four decimals ("20",
     * "8.875", "19.0000"), rounded half up (half away from zero if negative).
     * Done on integers, so 8.875% of a price is exact rather than a float guess.
     */
    public function percent(string $percent): self
    {
        if (! preg_match('/^(\d+)(?:\.(\d{1,4}))?$/', trim($percent), $matches)) {
            throw new InvalidArgumentException("'{$percent}' is not a valid percentage.");
        }

        // The rate in ten-thousandths of a percent: 20 => 200000, 8.875 => 88750.
        $rate = ((int) $matches[1]) * 10_000 + (int) str_pad($matches[2] ?? '', 4, '0');

        $scaled = abs($this->minor) * $rate;
        $rounded = intdiv($scaled + 500_000, 1_000_000);

        return new self($this->minor < 0 ? -$rounded : $rounded, $this->currency);
    }

    /**
     * Split this amount in proportion to the weights so the parts add up to
     * the amount exactly (largest remainder method). Used to share an order's
     * tax between its lines without losing or inventing a cent.
     *
     * @param  list<int>  $weights  Non-negative; if all are zero the first part takes everything.
     * @return list<self>
     */
    public function allocate(array $weights): array
    {
        if ($weights === []) {
            throw new InvalidArgumentException('Cannot allocate an amount across no parts.');
        }

        foreach ($weights as $weight) {
            if ($weight < 0) {
                throw new InvalidArgumentException('Allocation weights cannot be negative.');
            }
        }

        $amount = abs($this->minor);
        $total = array_sum($weights);

        if ($total === 0) {
            return array_map(
                fn (int $index) => new self($index === 0 ? $this->minor : 0, $this->currency),
                array_keys($weights),
            );
        }

        if ($amount > 0 && max($weights) > intdiv(PHP_INT_MAX, $amount)) {
            throw new \OverflowException('Amounts are too large to allocate exactly.');
        }

        $shares = [];
        $remainders = [];

        foreach ($weights as $index => $weight) {
            $shares[$index] = intdiv($amount * $weight, $total);
            $remainders[$index] = ($amount * $weight) % $total;
        }

        // Hand the leftover units, one each, to the parts that lost the most to rounding down.
        $leftover = $amount - array_sum($shares);
        $order = array_keys($remainders);
        usort($order, fn (int $a, int $b) => [$remainders[$b], $a] <=> [$remainders[$a], $b]);

        foreach (array_slice($order, 0, $leftover) as $index) {
            $shares[$index]++;
        }

        return array_map(
            fn (int $share) => new self($this->minor < 0 ? -$share : $share, $this->currency),
            array_values($shares),
        );
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->minor === $other->minor;
    }

    /**
     * The decimal string stored in a price column, for example "19.99".
     */
    public function toDecimal(): string
    {
        $exponent = self::exponentFor($this->currency);
        $absolute = abs($this->minor);
        $sign = $this->minor < 0 ? '-' : '';

        if ($exponent === 0) {
            return $sign.$absolute.'.00';
        }

        $divisor = 10 ** $exponent;

        return $sign.intdiv($absolute, $divisor).'.'.str_pad((string) ($absolute % $divisor), $exponent, '0', STR_PAD_LEFT);
    }

    public static function exponentFor(string $currency): int
    {
        return in_array(self::normaliseCurrency($currency), self::ZERO_DECIMAL, true) ? 0 : 2;
    }

    private static function normaliseCurrency(string $currency): string
    {
        $currency = strtoupper(trim($currency));

        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException("'{$currency}' is not a valid ISO 4217 currency code.");
        }

        if (in_array($currency, self::UNSUPPORTED, true)) {
            throw new InvalidArgumentException("{$currency} uses three decimal places and is not supported.");
        }

        return $currency;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException("Cannot combine {$this->currency} and {$other->currency} amounts.");
        }
    }
}
