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
     * @param  Money  $discount  What a discount code takes off this line (zero when there is none).
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
        public ?Money $discount = null,
    ) {}

    /**
     * What the customer pays for the line before tax: its price less any discount.
     */
    public function net(): Money
    {
        return $this->subtotal->subtract($this->discount ?? Money::zero($this->subtotal->currency));
    }

    public function withTax(Money $tax): self
    {
        return new self($this->item, $this->description, $this->unit, $this->subtotal, $this->requiresShipping, $this->taxable, $this->metadata, $tax, $this->discount);
    }

    public function withDiscount(Money $discount): self
    {
        return new self($this->item, $this->description, $this->unit, $this->subtotal, $this->requiresShipping, $this->taxable, $this->metadata, $this->tax, $discount);
    }
}
