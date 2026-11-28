<?php

namespace Database\Factories;

use App\Models\DataExport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DataExport>
 */
class DataExportFactory extends Factory
{
    protected $model = DataExport::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => DataExport::STATUS_PENDING,
            'format' => 'zip',
            'file_path' => null,
            'failure_reason' => null,
            'completed_at' => null,
        ];
    }

    public function completed(): self
    {
        return $this->state(fn () => [
            'status' => DataExport::STATUS_COMPLETED,
            // Named for the member, as every export file has to be.
            'file_path' => fn (array $attributes) => DataExport::filePrefix((int) $attributes['user_id']).$this->faker->uuid.'.zip',
            'completed_at' => now(),
        ]);
    }
}
