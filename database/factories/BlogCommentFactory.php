<?php

namespace Database\Factories;

use App\Models\BlogComment;
use App\Models\Blog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BlogComment>
 */
class BlogCommentFactory extends Factory
{
    protected $model = BlogComment::class;

    public function definition(): array
    {
        return [
            'blog_id' => Blog::factory(),
            'user_id' => User::factory(),
            'body' => $this->faker->sentences(2, true),
        ];
    }
}
