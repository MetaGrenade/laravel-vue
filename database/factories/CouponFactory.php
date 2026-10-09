<?php

namespace Database\Factories;

use App\Enums\CouponType;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Coupon>
 */
class CouponFactory extends Factory
{
    protected $model = Coupon::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(Str::random(8)),
            'description' => null,
            'type' => CouponType::Percent,
            'value' => '10',
            'currency' => null,
            'minimum_subtotal' => null,
            'starts_at' => null,
            'ends_at' => null,
            'max_redemptions' => null,
            'max_redemptions_per_customer' => null,
            'is_active' => true,
        ];
    }

    public function percent(string $percent): static
    {
        return $this->state(['type' => CouponType::Percent, 'value' => $percent, 'currency' => null]);
    }

    public function fixed(string $amount, string $currency = 'USD'): static
    {
        return $this->state(['type' => CouponType::Fixed, 'value' => $amount, 'currency' => $currency]);
    }

    public function freeShipping(): static
    {
        return $this->state(['type' => CouponType::FreeShipping, 'value' => null, 'currency' => null]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function scheduled(): static
    {
        return $this->state(['starts_at' => now()->addDay()]);
    }

    public function expired(): static
    {
        return $this->state(['starts_at' => now()->subWeek(), 'ends_at' => now()->subDay()]);
    }

    public function minimumSpend(string $amount): static
    {
        return $this->state(['minimum_subtotal' => $amount]);
    }

    public function limited(?int $total = null, ?int $perCustomer = null): static
    {
        return $this->state(['max_redemptions' => $total, 'max_redemptions_per_customer' => $perCustomer]);
    }

    /**
     * @param  iterable<Product>  $products
     */
    public function forProducts(iterable $products): static
    {
        return $this->afterCreating(fn (Coupon $coupon) => $coupon->products()->attach(collect($products)->pluck('id')->all()));
    }

    /**
     * @param  iterable<ProductCategory>  $categories
     */
    public function forCategories(iterable $categories): static
    {
        return $this->afterCreating(fn (Coupon $coupon) => $coupon->categories()->attach(collect($categories)->pluck('id')->all()));
    }
}
