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

class BlogFilteringTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_filter_blogs_by_category(): void
    {
        $categoryA = BlogCategory::factory()->create(['slug' => 'category-a']);
        $categoryB = BlogCategory::factory()->create();
        $tag = BlogTag::factory()->create();

        $matchingBlog = Blog::factory()->published()->create();
        $matchingBlog->categories()->sync([$categoryA->id]);
        $matchingBlog->tags()->sync([$tag->id]);

        $nonMatchingBlog = Blog::factory()->published()->create();
        $nonMatchingBlog->categories()->sync([$categoryB->id]);

        $response = $this->get(route('blogs.index', ['category' => $categoryA->slug]));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Blog')
            ->where('filters.category', $categoryA->slug)
            ->where('blogs.data', function ($data) use ($matchingBlog, $nonMatchingBlog) {
                $blogIds = collect($data)->pluck('id');

                return $blogIds->contains($matchingBlog->id)
                    && !$blogIds->contains($nonMatchingBlog->id);
            }));
    }

    public function test_can_filter_blogs_by_tag(): void
    {
        $tagA = BlogTag::factory()->create(['slug' => 'tag-a']);
        $tagB = BlogTag::factory()->create();
        $category = BlogCategory::factory()->create();

        $matchingBlog = Blog::factory()->published()->create();
        $matchingBlog->categories()->sync([$category->id]);
        $matchingBlog->tags()->sync([$tagA->id]);

        $nonMatchingBlog = Blog::factory()->published()->create();
        $nonMatchingBlog->categories()->sync([$category->id]);
        $nonMatchingBlog->tags()->sync([$tagB->id]);

        $response = $this->get(route('blogs.index', ['tag' => $tagA->slug]));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Blog')
            ->where('filters.tag', $tagA->slug)
            ->where('blogs.data', function ($data) use ($matchingBlog, $nonMatchingBlog) {
                $blogIds = collect($data)->pluck('id');

                return $blogIds->contains($matchingBlog->id)
                    && !$blogIds->contains($nonMatchingBlog->id);
            }));
    }

    public function test_can_filter_blogs_by_category_and_tag(): void
    {
        $category = BlogCategory::factory()->create(['slug' => 'category-c']);
        $tag = BlogTag::factory()->create(['slug' => 'tag-c']);

        $matchingBlog = Blog::factory()->published()->create();
        $matchingBlog->categories()->sync([$category->id]);
        $matchingBlog->tags()->sync([$tag->id]);

        $categoryOnlyBlog = Blog::factory()->published()->create();
        $categoryOnlyBlog->categories()->sync([$category->id]);

        $tagOnlyBlog = Blog::factory()->published()->create();
        $tagOnlyBlog->tags()->sync([$tag->id]);

        $response = $this->get(route('blogs.index', ['category' => $category->slug, 'tag' => $tag->slug]));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Blog')
            ->where('filters.category', $category->slug)
            ->where('filters.tag', $tag->slug)
            ->where('blogs.data', function ($data) use ($matchingBlog, $categoryOnlyBlog, $tagOnlyBlog) {
                $blogIds = collect($data)->pluck('id');

                return $blogIds->contains($matchingBlog->id)
                    && !$blogIds->contains($categoryOnlyBlog->id)
                    && !$blogIds->contains($tagOnlyBlog->id);
            }));
    }

    public function test_can_filter_blogs_by_keyword_search(): void
    {
        $matchingBlog = Blog::factory()->published()->create([
            'title' => 'Laravel testing strategies',
            'excerpt' => 'Practical guide to testing Laravel apps.',
        ]);

        $nonMatchingBlog = Blog::factory()->published()->create([
            'title' => 'Vue component patterns',
            'excerpt' => 'Tips for frontend engineers.',
        ]);

        $response = $this->get(route('blogs.index', ['search' => 'laravel']));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Blog')
            ->where('filters.search', 'laravel')
            ->where('blogs.data', function ($data) use ($matchingBlog, $nonMatchingBlog) {
                $blogIds = collect($data)->pluck('id');

                return $blogIds->contains($matchingBlog->id)
                    && !$blogIds->contains($nonMatchingBlog->id);
            }));
    }

    public function test_can_sort_blogs_by_popularity(): void
    {
        $user = User::factory()->create();

        $mostDiscussed = Blog::factory()->published()->create(['published_at' => now()->subDays(3)]);
        $moderatelyDiscussed = Blog::factory()->published()->create(['published_at' => now()->subDays(2)]);
        $leastDiscussed = Blog::factory()->published()->create(['published_at' => now()->subDay()]);

        foreach (range(1, 3) as $index) {
            BlogComment::create([
                'blog_id' => $mostDiscussed->id,
                'user_id' => $user->id,
                'body' => "Popular comment {$index}",
            ]);
        }

        BlogComment::create([
            'blog_id' => $moderatelyDiscussed->id,
            'user_id' => $user->id,
            'body' => 'A single comment',
        ]);

        $response = $this->get(route('blogs.index', ['sort' => 'popular']));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Blog')
            ->where('filters.sort', 'popular')
            ->where('blogs.data.0.id', $mostDiscussed->id)
            ->where('blogs.data.1.id', $moderatelyDiscussed->id)
            ->where('blogs.data.2.id', $leastDiscussed->id));
    }
}
