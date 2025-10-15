<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidDateInterval implements ValidationRule
{
    /**
     * Validate that the provided value can be parsed into a DateInterval.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $interval = @\DateInterval::createFromDateString((string) $value);

        if ($interval === false) {
            $fail('The :attribute must be a valid relative time expression.');
        }
    }
}
