<?php

namespace App\Support\Commerce;

use App\Models\Order;

/**
 * Thrown when starting a new checkout reveals that the previous one was in fact
 * paid. The shopper should be shown that order, not charged for another.
 */
class OrderAlreadyPaidException extends CheckoutException
{
    public function __construct(public readonly Order $order)
    {
        parent::__construct('Your earlier payment went through, so a new checkout was not started.');
    }
}
