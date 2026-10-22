<?php

namespace App\Support\Billing;

use RuntimeException;
use Throwable;

/**
 * Stripe's billing portal could not be opened for a customer. The message is for the customer; what Stripe said is the previous
 * exception, and is in the log.
 */
class PortalUnavailable extends RuntimeException
{
    /**
     * @param  'info'|'error'  $level  How the customer is told: nothing went wrong (they have no account yet), or something did.
     */
    public function __construct(string $message, public readonly string $level = 'error', ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /** The customer has no billing account yet: Stripe makes one when they subscribe or add a card. */
    public static function noBillingAccount(): self
    {
        return new self("You don't have a billing account with us yet. It is created when you subscribe.", 'info');
    }

    /** Stripe refused or could not be reached (typically: the portal's settings were never saved in the Stripe Dashboard). */
    public static function cannotBeOpened(?Throwable $previous = null): self
    {
        return new self("We couldn't open the billing portal just now. Please try again in a moment.", 'error', $previous);
    }
}
