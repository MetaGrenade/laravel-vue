<?php

namespace App\Support\Commerce\Discounts;

use App\Models\Coupon;
use App\Support\Commerce\Money;

/**
 * What a discount code takes off a particular order: an amount off the items, shared between
 * them line by line so the lines add up to it exactly, and/or the shipping charge.
 */
final readonly class Discount
{
    /**
     * @param  array<int, Money>  $perLine  Off each priced line, by its position (zero for lines the code does not touch).
     */
    public function __construct(
        public Coupon $coupon,
        public Money $onItems,
        public Money $onShipping,
        public array $perLine,
    ) {}

    public function total(): Money
    {
        return $this->onItems->add($this->onShipping);
    }

    public function forLine(int $position): Money
    {
        return $this->perLine[$position] ?? Money::zero($this->onItems->currency);
    }

    /**
     * What an order records about the code, so it stays true if the coupon is edited later.
     *
     * @return array<string, mixed>
     */
    public function toRecord(): array
    {
        return [
            'coupon_id' => $this->coupon->id,
            'code' => $this->coupon->code,
            'type' => $this->coupon->type->value,
            'items' => $this->onItems->toDecimal(),
            'shipping' => $this->onShipping->toDecimal(),
        ];
    }
}
