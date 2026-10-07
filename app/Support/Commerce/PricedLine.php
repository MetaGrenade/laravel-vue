<?php

namespace App\Support\Commerce;

use App\Models\CartItem;

/**
 * A cart line after it has been priced afresh from the catalogue.
 */
final readonly class PricedLine
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public CartItem $item,
        public string $description,
        public Money $unit,
        public Money $subtotal,
        public bool $requiresShipping,
        public bool $taxable,
        public array $metadata,
        public Money $tax,
    ) {}

    public function withTax(Money $tax): self
    {
        return new self($this->item, $this->description, $this->unit, $this->subtotal, $this->requiresShipping, $this->taxable, $this->metadata, $tax);
    }
}
