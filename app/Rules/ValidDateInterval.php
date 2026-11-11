<?php

namespace App\Rules;

use Closure;
use DateInterval;
use Illuminate\Contracts\Validation\ValidationRule;
use Throwable;

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

        if (self::parse((string) $value) === null) {
            $fail('The :attribute must be a valid relative time expression.');
        }
    }

    /**
     * Read a time written as text ("2 days", "90 minutes"), or null when it cannot be read. Since PHP 8.3 (this site needs 8.4)
     * `createFromDateString()` throws `DateMalformedIntervalStringException` for text it cannot read; it used to return false, which is
     * what this rule was written for, and `@` does not help with an exception.
     */
    public static function parse(string $value): ?DateInterval
    {
        try {
            return DateInterval::createFromDateString($value);
        } catch (Throwable) {
            return null;
        }
    }
}
