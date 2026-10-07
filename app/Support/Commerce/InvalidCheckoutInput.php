<?php

namespace App\Support\Commerce;

/**
 * Something the shopper entered or chose at checkout cannot be accepted (an
 * address we cannot ship to, a shipping method that is no longer offered).
 * Unlike other checkout problems it is fixed on the checkout form, so the
 * shopper stays there instead of being sent back to the cart.
 */
class InvalidCheckoutInput extends CheckoutException
{
    public function __construct(string $message, public readonly string $field = 'checkout')
    {
        parent::__construct($message);
    }
}
