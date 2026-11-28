<?php

namespace App\Rules;

use App\Support\Database\TimestampRange;
use App\Support\Localization\BrowserTime;
use Carbon\Exceptions\InvalidFormatException;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A date that a `timestamp` column can keep (TimestampRange). It goes beside the `date` rule, which accepts a year 1 or a year 100000.
 */
final class StorableDate implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // What is not a date at all is for the `date` rule to say.
        if (! is_string($value)) {
            return;
        }

        try {
            $moment = BrowserTime::parse($value);
        } catch (InvalidFormatException) {
            return;
        }

        if ($moment !== null && ! TimestampRange::holds($moment)) {
            $fail(sprintf('Choose a date from %d to %d.', TimestampRange::FIRST_YEAR, TimestampRange::LAST_YEAR));
        }
    }
}
