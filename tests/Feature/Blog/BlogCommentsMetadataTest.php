<?php

namespace Tests\Feature\Blog;

use App\Models\Blog;
use App\Models\BlogComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BlogCommentsMetadataTest extends TestCase
{
    use RefreshDatabase;

    public function test_comment_payload_includes_profile_metadata(): void
    {
        $author = User::factory()->create();
        $commenter = User::factory()->create([
            'avatar_url' => 'https://cdn.example.com/commenter.png',
            'profile_bio' => 'Product designer who loves sharing release notes.',
        ]);

        $blog = Blog::factory()
            ->published()
            ->create([
                'user_id' => $author->id,
                'status' => 'published',
            ]);

        BlogComment::create([
            'blog_id' => $blog->id,
            'user_id' => $commenter->id,
            'body' => 'Looking forward to trying this update!',
        ]);

        $response = $this->getJson(route('blogs.comments.index', ['blog' => $blog->slug]));

        $response->assertOk();

        $response->assertJsonPath('data.0.user.id', $commenter->id);
        $response->assertJsonPath('data.0.user.avatar_url', 'https://cdn.example.com/commenter.png');
        $response->assertJsonPath('data.0.user.profile_bio', 'Product designer who loves sharing release notes.');
        $response->assertJsonPath('data.0.user.name', $commenter->name);
    }

    public function test_inertia_comment_payload_includes_profile_metadata(): void
    {
        $author = User::factory()->create();
        $commenter = User::factory()->create([
            'avatar_url' => 'https://cdn.example.com/commenter.png',
            'profile_bio' => 'Product designer who loves sharing release notes.',
        ]);

        $blog = Blog::factory()
            ->published()
            ->create([
                'user_id' => $author->id,
                'status' => 'published',
            ]);

        BlogComment::create([
            'blog_id' => $blog->id,
            'user_id' => $commenter->id,
            'body' => 'Looking forward to trying this update!',
        ]);

        $response = $this->get(route('blogs.view', ['slug' => $blog->slug]));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('BlogView')
            ->where('comments.data.0.user.id', $commenter->id)
            ->where('comments.data.0.user.avatar_url', 'https://cdn.example.com/commenter.png')
            ->where('comments.data.0.user.profile_bio', 'Product designer who loves sharing release notes.')
            ->where('comments.data.0.user.name', $commenter->name));
    }
}
