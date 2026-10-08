<?php

namespace App\Support\Commerce;

/**
 * Where an order goes, as far as shipping and tax are concerned: a country and
 * optionally a region. A live quote needs only this, not a whole address.
 */
final readonly class Destination
{
    public function __construct(
        public string $country,
        public ?string $region = null,
    ) {}

    public static function make(?string $country, ?string $region = null): ?self
    {
        $country = strtoupper(trim((string) $country));

        if (! Countries::isValid($country)) {
            return null;
        }

        $region = trim((string) $region);

        return new self($country, $region === '' ? null : $region);
    }
}
