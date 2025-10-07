<?php

namespace Database\Factories;

use App\Models\SearchQuery;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SearchQuery>
 */
class SearchQueryFactory extends Factory
{
    protected $model = SearchQuery::class;

    public function definition(): array
    {
        return [
            'term' => $this->faker->words(2, true),
            'result_count' => $this->faker->numberBetween(0, 50),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
