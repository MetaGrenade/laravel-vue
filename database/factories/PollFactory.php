<?php

namespace Database\Factories;

use App\Models\Poll;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Poll>
 */
class PollFactory extends Factory
{
    protected $model = Poll::class;

    public function definition(): array
    {
        $title = $this->faker->sentence(6);

        return [
            'user_id' => User::factory(),
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::random(5),
            'description' => $this->faker->paragraph(),
            'status' => 'draft',
            'allow_multiple' => false,
            'starts_at' => null,
            'ends_at' => null,
        ];
    }

    public function published(): self
    {
        return $this->state(fn () => [
            'status' => 'published',
            'starts_at' => null,
            'ends_at' => null,
        ]);
    }

    public function closed(): self
    {
        return $this->state(fn () => [
            'status' => 'closed',
        ]);
    }

    public function scheduled(): self
    {
        return $this->state(fn () => [
            'status' => 'published',
            'starts_at' => now()->addDay(),
        ]);
    }
}
