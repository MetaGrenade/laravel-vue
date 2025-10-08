<?php

namespace Database\Factories;

use App\Models\DataErasureRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DataErasureRequest>
 */
class DataErasureRequestFactory extends Factory
{
    protected $model = DataErasureRequest::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => DataErasureRequest::STATUS_PENDING,
            'processed_at' => null,
        ];
    }

    public function completed(): self
    {
        return $this->state(fn () => [
            'status' => DataErasureRequest::STATUS_COMPLETED,
            'processed_at' => now(),
        ]);
    }
}
