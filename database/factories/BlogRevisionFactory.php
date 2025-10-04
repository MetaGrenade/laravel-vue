<?php

namespace Database\Factories;

use App\Models\Blog;
use App\Models\BlogRevision;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BlogRevision>
 */
class BlogRevisionFactory extends Factory
{
    protected $model = BlogRevision::class;

    public function definition(): array
    {
        $title = $this->faker->sentence(6);

        return [
            'blog_id' => Blog::factory(),
            'editor_id' => User::factory(),
            'title' => $title,
            'excerpt' => $this->faker->paragraph(),
            'body' => $this->faker->paragraphs(3, true),
            'metadata' => [
                'slug' => Str::slug($title) . '-' . Str::random(5),
                'status' => 'draft',
                'cover_image' => null,
                'published_at' => null,
                'scheduled_for' => null,
                'category_ids' => [],
                'tag_ids' => [],
            ],
        ];
    }
}
