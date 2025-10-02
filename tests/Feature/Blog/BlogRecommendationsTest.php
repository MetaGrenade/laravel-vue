<?php

namespace Tests\Feature\Blog;

use App\Models\Blog;
use App\Models\BlogCategory;
use App\Models\BlogComment;
use App\Models\BlogTag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BlogRecommendationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_blog_view_includes_related_recommendations(): void
    {
        $category = BlogCategory::factory()->create();
        $tag = BlogTag::factory()->create();

        $blog = Blog::factory()->published()->create();
        $blog->categories()->sync([$category->id]);
        $blog->tags()->sync([$tag->id]);

        $relatedByCategory = Blog::factory()->published()->create(['title' => 'Category Companion']);
        $relatedByCategory->categories()->sync([$category->id]);

        $relatedByTag = Blog::factory()->published()->create(['title' => 'Tagged Ally']);
        $relatedByTag->tags()->sync([$tag->id]);

        $unrelated = Blog::factory()->published()->create();

        $response = $this->get(route('blogs.view', ['slug' => $blog->slug]));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('BlogView')
            ->where('blog.id', $blog->id)
            ->where('blog.recommendations', function ($recommendations) use ($relatedByCategory, $relatedByTag, $unrelated) {
                $ids = collect($recommendations)->pluck('id');

                return $ids->contains($relatedByCategory->id)
                    && $ids->contains($relatedByTag->id)
                    && !$ids->contains($unrelated->id);
            }));
    }

    public function test_blog_view_uses_popular_and_latest_fallbacks_when_no_related_posts(): void
    {
        $blog = Blog::factory()->published()->create();

        $popular = Blog::factory()->published()->create(['title' => 'Popular Choice']);
        $alsoPopular = Blog::factory()->published()->create(['title' => 'Another Hit']);
        $latest = Blog::factory()->published()->create(['title' => 'Fresh Content']);

        $commentAuthor = User::factory()->create();
        BlogComment::query()->create([
            'blog_id' => $popular->id,
            'user_id' => $commentAuthor->id,
            'body' => 'Insightful read!',
        ]);
        BlogComment::query()->create([
            'blog_id' => $alsoPopular->id,
            'user_id' => $commentAuthor->id,
            'body' => 'Great perspective.',
        ]);

        $response = $this->get(route('blogs.view', ['slug' => $blog->slug]));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('BlogView')
            ->where('blog.id', $blog->id)
            ->where('blog.recommendations', function ($recommendations) use ($popular, $alsoPopular, $latest) {
                $ids = collect($recommendations)->pluck('id');

                return $ids->contains($popular->id)
                    && $ids->contains($alsoPopular->id)
                    && $ids->contains($latest->id);
            }));
    }
}
