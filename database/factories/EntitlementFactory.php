<?php

namespace Database\Factories;

use App\Enums\EntitlementType;
use App\Models\Entitlement;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Entitlement>
 */
class EntitlementFactory extends Factory
{
    protected $model = Entitlement::class;

    public function definition(): array
    {
        $word = Str::lower(Str::random(8));

        return [
            'key' => "feature.{$word}",
            'label' => 'Feature '.$word,
            'description' => null,
            'type' => EntitlementType::Feature,
        ];
    }

    public function limit(): static
    {
        return $this->state(fn () => ['type' => EntitlementType::Limit]);
    }
}
