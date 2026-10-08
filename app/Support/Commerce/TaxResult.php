<?php

namespace App\Support\Commerce;

/**
 * The tax on an order: one line per rate, and how much of it falls on the items
 * versus the shipping charge.
 */
final readonly class TaxResult
{
    /**
     * @param  list<TaxLine>  $lines
     */
    public function __construct(
        public array $lines,
        public Money $onItems,
        public Money $onShipping,
    ) {}

    public function total(): Money
    {
        return $this->onItems->add($this->onShipping);
    }
}
