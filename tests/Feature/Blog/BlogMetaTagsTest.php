<?php

namespace Tests\Feature\Blog;

use App\Models\Blog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BlogMetaTagsTest extends TestCase
{
    use RefreshDatabase;

    public function test_blog_view_renders_expected_meta_tags(): void
    {
        $blog = Blog::factory()->published()->create([
            'title' => 'Meta Ready Post',
            'slug' => 'meta-ready-post',
            'excerpt' => 'A concise excerpt for testing metadata rendering.',
            'cover_image' => 'covers/meta-ready.jpg',
        ]);

        $blog->user->forceFill([
            'nickname' => 'Jane Doe',
        ])->save();

        $response = $this->get(route('blogs.view', ['slug' => $blog->slug]));

        $canonicalUrl = route('blogs.view', ['slug' => $blog->slug]);

        $response->assertOk();
        $response->assertSee('<meta name="description" content="A concise excerpt for testing metadata rendering."', false);
        $response->assertSee('<link rel="canonical" href="' . \e($canonicalUrl) . '"', false);
        $response->assertSee('<meta property="og:title" content="Meta Ready Post"', false);
        $response->assertSee('<meta property="og:description" content="A concise excerpt for testing metadata rendering."', false);
        $response->assertSee('<meta property="og:url" content="' . \e($canonicalUrl) . '"', false);
        $response->assertSee('<meta name="twitter:title" content="Meta Ready Post"', false);
        $response->assertSee('<meta name="twitter:description" content="A concise excerpt for testing metadata rendering."', false);
        $response->assertSee('<meta name="twitter:card" content="summary_large_image"', false);
        $response->assertSee('<meta name="twitter:creator" content="Jane Doe"', false);
    }
}
