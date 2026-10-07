<?php

namespace App\Support\Commerce;

/**
 * What a cart costs once shipping and tax are known: the single answer used for
 * the live quote on the checkout page and for the order that is placed, so the
 * two can never disagree.
 */
final readonly class Pricing
{
    /**
     * @param  list<PricedLine>  $lines
     * @param  list<ShippingOption>  $shippingOptions
     * @param  list<TaxLine>  $taxLines
     */
    public function __construct(
        public string $currency,
        public array $lines,
        public Money $subtotal,
        public bool $needsShipping,
        public array $shippingOptions,
        public ?ShippingOption $shipping,
        public bool $canShip,
        public ?string $shippingMessage,
        public array $taxLines,
        public Money $itemTax,
        public Money $shippingTax,
        public Money $discount,
        public Money $grandTotal,
        public bool $regionRequired = false,
    ) {}

    public function shippingTotal(): Money
    {
        return $this->shipping->amount ?? Money::zero($this->currency);
    }

    public function taxTotal(): Money
    {
        return $this->itemTax->add($this->shippingTax);
    }

    /**
     * The quote shown on the checkout page.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'currency' => $this->currency,
            'subtotal' => $this->subtotal->toDecimal(),
            'needs_shipping' => $this->needsShipping,
            'shipping' => [
                'can_ship' => $this->canShip,
                'message' => $this->shippingMessage,
                'options' => array_map(fn (ShippingOption $option) => $option->toArray(), $this->shippingOptions),
                'selected_id' => $this->shipping?->id,
                'amount' => $this->shippingTotal()->toDecimal(),
            ],
            'tax' => [
                'lines' => array_map(fn (TaxLine $line) => $line->toArray(), $this->taxLines),
                'total' => $this->taxTotal()->toDecimal(),
                // Tax in this country depends on the state or province, so the customer must give one.
                'region_required' => $this->regionRequired,
            ],
            'discount_total' => $this->discount->toDecimal(),
            'grand_total' => $this->grandTotal->toDecimal(),
        ];
    }
}
