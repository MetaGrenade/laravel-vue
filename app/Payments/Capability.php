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

    /**
     * Refunding less than the whole payment. A provider that has {@see self::REFUNDS} but not this one (Tebex's API takes no
     * amount) can only be asked to return a payment in full; a smaller refund can only be recorded as one made by hand.
     */
    public const PARTIAL_REFUNDS = 'partial_refunds';

    public const COUPONS = 'coupons';

    public const HOSTED_CHECKOUT = 'hosted_checkout';

    public const EMBEDDED_CHECKOUT = 'embedded_checkout';

    private function __construct() {}
}
