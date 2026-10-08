<?php

namespace App\Support\Commerce;

/**
 * What the shopper chose at checkout besides the cart: where to ship, where to
 * bill and which shipping method.
 */
final readonly class CheckoutInput
{
    public function __construct(
        public ?AddressData $shippingAddress = null,
        public ?AddressData $billingAddress = null,
        public ?int $shippingRateId = null,
    ) {}
}
