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
        return (bool) SystemSetting::get(
            'email_verification_required',
            (bool) config('auth.must_verify_email', false)
        );
    }
}
