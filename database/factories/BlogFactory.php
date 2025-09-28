<?php

namespace Database\Factories;

use App\Models\Blog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Blog>
 */
class BlogFactory extends Factory
{
    protected $model = Blog::class;

    public function definition(): array
    {
        $title = $this->faker->sentence(6, true);

        return [
            'title' => $title,
            'slug' => Str::slug($title) . '-' . Str::lower(Str::random(5)),
            'excerpt' => $this->faker->paragraph(),
            'body' => collect(range(1, 4))->map(fn () => $this->faker->paragraph())->implode("\n\n"),
            'user_id' => User::factory(),
            'status' => 'published',
            'published_at' => now(),
        ];
    }

    public function draft(): self
    {
        return $this->state(fn () => [
            'status' => 'draft',
            'published_at' => null,
        ]);
    }
}
