<?php

namespace App\Rules;

use App\Support\Commerce\Countries;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A two-letter ISO 3166-1 country code, optionally also the "*" marker that
 * stands for every country not listed elsewhere (a rest-of-world zone or a
 * fallback tax rate).
 */
class CountryCode implements ValidationRule
{
    public function __construct(private readonly bool $allowAny = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a country code.');

            return;
        }

        $code = strtoupper(trim($value));

        if ($this->allowAny && $code === Countries::ANY) {
            return;
        }

        if (! Countries::isValid($code)) {
            $fail('The :attribute must be a valid two-letter country code.');
        }
    }
}
