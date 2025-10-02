<?php

namespace Database\Factories;

use App\Models\SupportTicketCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\SupportTicketCategory>
 */
class SupportTicketCategoryFactory extends Factory
{
    protected $model = SupportTicketCategory::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->words(2, true),
        ];
    }
}
