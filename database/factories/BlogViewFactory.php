<?php

namespace Database\Factories;

use App\Models\Blog;
use App\Models\BlogView;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BlogView>
 */
class BlogViewFactory extends Factory
{
    protected $model = BlogView::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'blog_id' => Blog::factory(),
            'view_count' => 1,
            'last_viewed_at' => now(),
        ];
    }
}
