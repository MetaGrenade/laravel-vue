<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\BlogCategory;
use App\Models\BlogComment;
use App\Models\BlogTag;
use App\Models\BlogView;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get('/dashboard');
        $response->assertRedirect('/login');
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get('/dashboard');
        $response->assertStatus(200);
    }

    public function test_dashboard_recommends_articles_from_view_history(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $category = BlogCategory::factory()->create();
        $tag = BlogTag::factory()->create();

        $viewedBlog = Blog::factory()->published()->create();
        $viewedBlog->categories()->sync([$category->id]);
        $viewedBlog->tags()->sync([$tag->id]);

        $alreadyRead = Blog::factory()->published()->create();
        $alreadyRead->categories()->sync([$category->id]);

        $recommendedByCategory = Blog::factory()->published()->create([
            'published_at' => now()->subMinutes(10),
        ]);
        $recommendedByCategory->categories()->sync([$category->id]);

        $recommendedByTag = Blog::factory()->published()->create([
            'published_at' => now()->subMinutes(5),
        ]);
        $recommendedByTag->tags()->sync([$tag->id]);

        Blog::factory()->count(3)->published()->create();

        BlogView::query()->create([
            'user_id' => $user->id,
            'blog_id' => $viewedBlog->id,
            'view_count' => 3,
            'last_viewed_at' => now()->subDay(),
        ]);

        BlogView::query()->create([
            'user_id' => $user->id,
            'blog_id' => $alreadyRead->id,
            'view_count' => 1,
            'last_viewed_at' => now()->subHours(12),
        ]);

        $response = $this->get('/dashboard');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('recommendedArticles.0.id', $recommendedByTag->id)
            ->where('recommendedArticles.1.id', $recommendedByCategory->id)
            ->where('recommendedArticles', function ($articles) use ($viewedBlog, $alreadyRead) {
                $ids = collect($articles)->pluck('id');

                return !$ids->contains($viewedBlog->id)
                    && !$ids->contains($alreadyRead->id);
            }));
    }

    public function test_dashboard_recommendations_fallback_to_popular_when_no_history(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $mostPopular = Blog::factory()->published()->create(['published_at' => now()->subDays(2)]);
        $lessPopular = Blog::factory()->published()->create(['published_at' => now()->subDays(1)]);
        $fresh = Blog::factory()->published()->create(['published_at' => now()]);

        $commentAuthor = User::factory()->create();

        BlogComment::query()->create([
            'blog_id' => $mostPopular->id,
            'user_id' => $commentAuthor->id,
            'body' => 'Great read!',
        ]);
        BlogComment::query()->create([
            'blog_id' => $mostPopular->id,
            'user_id' => $commentAuthor->id,
            'body' => 'Loved it!',
        ]);
        BlogComment::query()->create([
            'blog_id' => $mostPopular->id,
            'user_id' => $commentAuthor->id,
            'body' => 'So helpful.',
        ]);

        BlogComment::query()->create([
            'blog_id' => $lessPopular->id,
            'user_id' => $commentAuthor->id,
            'body' => 'Nice insights.',
        ]);

        $response = $this->get('/dashboard');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('recommendedArticles', function ($articles) use ($mostPopular, $lessPopular, $fresh) {
                $ids = collect($articles)->pluck('id');

                return $ids->take(3)->values()->all() === [
                    $mostPopular->id,
                    $lessPopular->id,
                    $fresh->id,
                ];
            }));
    }
}
