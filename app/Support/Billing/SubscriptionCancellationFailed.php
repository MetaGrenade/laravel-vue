<?php

namespace App\Support\Billing;

use RuntimeException;

/**
 * A subscription could not be ended at Stripe, so it may still be charging the customer. Whatever asked must not go
 * on as if it had been (an account is not deleted). The Stripe error that caused it is the previous exception.
 */
class SubscriptionCancellationFailed extends RuntimeException {}
