<?php

namespace App\Support;

use App\Models\SystemSetting;

class EmailVerification
{
    /**
     * Determine if email verification is required for user access.
     */
    public static function isRequired(): bool
    {
        $value = SystemSetting::get('email_verification_required');

        if ($value === null) {
            return static::normalize(config('auth.must_verify_email', false));
        }

        return static::normalize($value);
    }

    /**
     * Normalize the mixed value to a strict boolean.
     */
    protected static function normalize(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $normalized = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

            if ($normalized !== null) {
                return $normalized;
            }
        }

        return (bool) $value;
    }
}
