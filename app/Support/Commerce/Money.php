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
