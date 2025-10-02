<?php

namespace Database\Factories;

use App\Models\Faq;
use App\Models\FaqCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Faq>
 */
class FaqFactory extends Factory
{
    protected $model = Faq::class;

    public function definition(): array
    {
        $question = rtrim($this->faker->sentence(6, true), '.');

        return [
            'faq_category_id' => FaqCategory::factory(),
            'question' => $question.'?',
            'answer' => $this->faker->paragraph(),
            'order' => $this->faker->numberBetween(0, 20),
            'published' => $this->faker->boolean(),
        ];
    }
}
