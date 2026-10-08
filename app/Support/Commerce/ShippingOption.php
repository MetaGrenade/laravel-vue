<?php

namespace App\Support\Commerce;

use App\Models\ShippingRate;

/**
 * A shipping method offered to the customer, with its price in the store currency.
 */
final readonly class ShippingOption
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $description,
        public Money $amount,
    ) {}

    public static function fromRate(ShippingRate $rate, string $currency): self
    {
        return new self(
            id: $rate->id,
            name: $rate->name,
            description: $rate->description,
            amount: Money::parse($rate->amount, $currency),
        );
    }

    /**
     * @return array{id: int, name: string, description: string|null, amount: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'amount' => $this->amount->toDecimal(),
        ];
    }
}
