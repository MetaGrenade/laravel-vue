<?php

namespace App\Payments;

/**
 * Things a payment provider may support. The shop checks these instead of
 * checking which provider is active.
 */
final class Capability
{
    public const ONE_TIME_PAYMENTS = 'one_time_payments';

    public const SUBSCRIPTIONS = 'subscriptions';

    public const REFUNDS = 'refunds';

    public const COUPONS = 'coupons';

    public const HOSTED_CHECKOUT = 'hosted_checkout';

    public const EMBEDDED_CHECKOUT = 'embedded_checkout';

    private function __construct() {}
}
