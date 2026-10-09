<?php

namespace App\Support\Commerce\Discounts;

/**
 * How a discount code is written down. Customers type codes in any case and with stray spaces,
 * so every code is stored and looked up in one form.
 */
final class CouponCode
{
    /** What a code may contain: letters, digits, hyphens and underscores. */
    public const PATTERN = '/^[A-Z0-9][A-Z0-9_-]*$/';

    public static function normalise(string $code): string
    {
        return strtoupper(trim($code));
    }

    public static function isWellFormed(string $code): bool
    {
        return strlen($code) <= 40 && preg_match(self::PATTERN, $code) === 1;
    }
}
