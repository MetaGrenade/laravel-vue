<?php

namespace App\Support\Commerce;

/**
 * One tax charged on an order: which tax, at what rate, and how much.
 */
final readonly class TaxLine
{
    public function __construct(
        public string $name,
        public string $rate,
        public Money $amount,
    ) {}

    /**
     * @return array{name: string, rate: string, amount: string}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'rate' => $this->rate, 'amount' => $this->amount->toDecimal()];
    }
}
