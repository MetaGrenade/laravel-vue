<?php

namespace App\Payments\Data;

/**
 * What the provider confirmed when asked to close a checkout.
 */
enum CheckoutClosure: string
{
    /**
     * The checkout is closed: no further payment can be taken through it.
     */
    case Closed = 'closed';

    /**
     * It had already been paid. The payment has been applied to the order.
     */
    case Paid = 'paid';

    /**
     * It could not be closed and is not confirmed paid: a payment that is still
     * being processed (for example a bank debit) or one held for review. The
     * order must be left as it is.
     */
    case Unresolved = 'unresolved';
}
