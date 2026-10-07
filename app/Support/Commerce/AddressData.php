<?php

namespace App\Support\Commerce;

use App\Models\Address;

/**
 * An address as it travels through checkout and is stored on an order: a plain
 * snapshot, independent of the address book.
 */
final readonly class AddressData
{
    public function __construct(
        public string $name,
        public string $line1,
        public string $city,
        public string $country,
        public ?string $company = null,
        public ?string $line2 = null,
        public ?string $region = null,
        public ?string $postalCode = null,
        public ?string $phone = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Keys as stored: name, line1, city, country, postal_code, ...
     */
    public static function fromArray(array $data): self
    {
        $clean = fn (string $key): ?string => isset($data[$key]) && trim((string) $data[$key]) !== '' ? trim((string) $data[$key]) : null;

        return new self(
            name: (string) $clean('name'),
            line1: (string) $clean('line1'),
            city: (string) $clean('city'),
            country: strtoupper((string) $clean('country')),
            company: $clean('company'),
            line2: $clean('line2'),
            region: $clean('region'),
            postalCode: $clean('postal_code'),
            phone: $clean('phone'),
        );
    }

    public static function fromModel(Address $address): self
    {
        return self::fromArray($address->only([
            'name', 'company', 'line1', 'line2', 'city', 'region', 'postal_code', 'country', 'phone',
        ]));
    }

    /**
     * The shape stored on an order and returned to the browser.
     *
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'company' => $this->company,
            'line1' => $this->line1,
            'line2' => $this->line2,
            'city' => $this->city,
            'region' => $this->region,
            'postal_code' => $this->postalCode,
            'country' => $this->country,
            'phone' => $this->phone,
        ];
    }
}
