<?php

namespace App\Enums;

/**
 * What a discount code does to an order.
 */
enum CouponType: string
{
    /** A percentage off the items it applies to. */
    case Percent = 'percent';

    /** A fixed amount off the items it applies to, never more than they cost. */
    case Fixed = 'fixed';

    /** The shipping charge is waived. Items keep their price. */
    case FreeShipping = 'free_shipping';

    public function label(): string
    {
        return match ($this) {
            self::Percent => 'Percentage off',
            self::Fixed => 'Amount off',
            self::FreeShipping => 'Free shipping',
        };
    }

    /**
     * Whether the code reduces the price of items (and so can be limited to some of them).
     */
    public function discountsItems(): bool
    {
        return $this !== self::FreeShipping;
    }
}
