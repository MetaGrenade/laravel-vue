<?php

namespace App\Support\Billing;

use Illuminate\Validation\ValidationException;

/**
 * A promotion code that cannot be used, said in words the customer can act on. It is a validation error on the
 * `promotion_code` field, so the framework answers a form or a fetch with it the way it does any other bad input.
 */
class PromotionCodeRejected extends ValidationException
{
    public static function because(string $message): static
    {
        return static::withMessages(['promotion_code' => [$message]]);
    }

    /** Not a code Stripe has, or one that has run out, expired or been switched off (Stripe does not say which). */
    public static function notValid(): static
    {
        return static::because(__('forms.promotion_code.not_valid'));
    }

    /** Stripe could not be asked, so the code was neither accepted nor refused. */
    public static function cannotBeChecked(): static
    {
        return static::because(__('forms.promotion_code.cannot_check'));
    }

    /** Stripe refused it when the subscription was started, for a condition that could not be seen beforehand. */
    public static function refusedByStripe(): static
    {
        return static::because(__('forms.promotion_code.refused_by_stripe'));
    }
}
