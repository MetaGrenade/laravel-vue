<?php

namespace Database\Factories;

use App\Enums\ReviewStatus;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Makes the row only. Whether it is "verified" or published is whatever the test says: nothing here checks
 * the reviewer's orders.
 *
 * @extends Factory<ProductReview>
 */
class ProductReviewFactory extends Factory
{
    protected $model = ProductReview::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'user_id' => User::factory(),
            'rating' => fake()->numberBetween(1, 5),
            'title' => fake()->sentence(3),
            'body' => fake()->paragraph(),
            'status' => ReviewStatus::Published,
            'verified_purchase' => false,
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => ReviewStatus::Pending]);
    }

    public function rejected(): static
    {
        return $this->state(['status' => ReviewStatus::Rejected]);
    }

    public function verified(): static
    {
        return $this->state(['verified_purchase' => true]);
    }

    public function rated(int $rating): static
    {
        return $this->state(['rating' => $rating]);
    }
}
