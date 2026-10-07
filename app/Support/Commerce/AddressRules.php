<?php

namespace App\Support\Commerce;

use Illuminate\Validation\Rule;

/**
 * Validation rules for an address, shared by checkout and the address book.
 */
final class AddressRules
{
    /**
     * Rules for the address fields under a prefix ("shipping_address" gives
     * "shipping_address.line1" and so on; an empty prefix gives top-level fields).
     *
     * @param  string|null  $country  The country being submitted; a postal code is only required where the country uses them.
     * @return array<string, list<mixed>>
     */
    public static function for(string $prefix = '', ?string $country = null): array
    {
        $key = fn (string $field) => $prefix === '' ? $field : "{$prefix}.{$field}";
        $country = strtoupper(trim((string) $country));

        $rules = [
            $key('name') => ['required', 'string', 'max:120'],
            $key('company') => ['nullable', 'string', 'max:120'],
            $key('line1') => ['required', 'string', 'max:255'],
            $key('line2') => ['nullable', 'string', 'max:255'],
            $key('city') => ['required', 'string', 'max:120'],
            $key('region') => ['nullable', 'string', 'max:120'],
            $key('postal_code') => [
                Countries::isValid($country) && ! Countries::usesPostalCodes($country) ? 'nullable' : 'required',
                'string',
                'max:32',
            ],
            $key('country') => ['required', 'string', Rule::in(Countries::codes())],
            $key('phone') => ['nullable', 'string', 'max:40'],
        ];

        if ($prefix !== '') {
            $rules[$prefix] = ['required', 'array'];
        }

        return $rules;
    }

    /**
     * Plain-language names for the fields, so an error reads "The postal code field
     * is required" rather than "The shipping address.postal_code field".
     *
     * @return array<string, string>
     */
    public static function attributes(string $prefix = ''): array
    {
        $names = [
            'name' => 'name',
            'company' => 'company',
            'line1' => 'address',
            'line2' => 'apartment or suite',
            'city' => 'city',
            'region' => 'state or province',
            'postal_code' => 'postal code',
            'country' => 'country',
            'phone' => 'phone number',
        ];

        $attributes = [];

        foreach ($names as $field => $label) {
            $attributes[$prefix === '' ? $field : "{$prefix}.{$field}"] = $label;
        }

        return $attributes;
    }

    /**
     * Make country codes case-insensitive before validation.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalise(array $input): array
    {
        if (isset($input['country']) && is_string($input['country'])) {
            $input['country'] = strtoupper(trim($input['country']));
        }

        return $input;
    }
}
