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
        $title = $this->faker->sentence(6);

        return [
            'title' => $title,
            'slug' => Str::slug($title) . '-' . Str::random(5),
            'excerpt' => $this->faker->paragraph(),
            'body' => $this->faker->paragraphs(3, true),
            'user_id' => User::factory(),
            'status' => 'draft',
            'published_at' => null,
        ];
    }

    public function published(): self
    {
        return $this->state(function () {
            return [
                'status' => 'published',
                'published_at' => now(),
            ];
        });
    }

    public function archived(): self
    {
        return $this->state(fn () => [
            'status' => 'archived',
            'published_at' => null,
        ]);
    }
}
