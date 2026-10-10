<?php

namespace App\Support\Commerce;

use App\Enums\CouponType;
use App\Support\Commerce\Discounts\Discount;

/**
 * What a cart costs once shipping and tax are known: the single answer used for
 * the live quote on the checkout page and for the order that is placed, so the
 * two can never disagree.
 *
 * The totals always add up as: subtotal, plus shipping, plus tax, less the discount. Shipping
 * and the subtotal are shown before any discount (a free-shipping code is part of the discount),
 * and tax is charged on what is left after it.
 */
final readonly class Pricing
{
    /**
     * @param  list<PricedLine>  $lines
     * @param  list<ShippingOption>  $shippingOptions
     * @param  list<TaxLine>  $taxLines
     * @param  Discount|null  $applied  The discount code's effect, when one is on the cart and can be used.
     * @param  string|null  $couponCode  The code on the cart, whether or not it could be used.
     * @param  string|null  $couponProblem  Why the code on the cart could not be used, for the shopper.
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
        public ?Discount $applied = null,
        public ?string $couponCode = null,
        public ?string $couponProblem = null,
    ) {}

    public function shippingTotal(): Money
    {
        return $this->shipping->amount ?? Money::zero($this->currency);
    }

    /**
     * The part of the shipping charge a discount code waives.
     */
    public function shippingDiscount(): Money
    {
        return $this->applied->onShipping ?? Money::zero($this->currency);
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
                // What a discount code takes off the shipping charge (the whole of it for free shipping).
                'discount' => $this->shippingDiscount()->toDecimal(),
            ],
            'tax' => [
                'lines' => array_map(fn (TaxLine $line) => $line->toArray(), $this->taxLines),
                'total' => $this->taxTotal()->toDecimal(),
                // Tax in this country depends on the state or province, so the customer must give one.
                'region_required' => $this->regionRequired,
            ],
            'coupon' => $this->couponCode === null ? null : [
                'code' => $this->couponCode,
                'applied' => $this->applied !== null,
                'problem' => $this->couponProblem,
                'free_shipping' => $this->applied?->coupon->type === CouponType::FreeShipping,
            ],
            'discount_total' => $this->discount->toDecimal(),
            'grand_total' => $this->grandTotal->toDecimal(),
        ];
    }
}
