<?php

namespace Tests\Feature\Api;

use App\Models\Blog;
use App\Models\BlogComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BlogCommentTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_can_list_comments(): void
    {
        $blog = Blog::factory()->create();
        BlogComment::factory()->count(3)->create(['blog_id' => $blog->id]);

        $response = $this->getJson(route('api.blogs.comments.index', ['blog' => $blog->slug]));

        $response
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_guest_cannot_create_comment(): void
    {
        $blog = Blog::factory()->create();

        $response = $this->postJson(route('api.blogs.comments.store', ['blog' => $blog->slug]), [
            'body' => 'First!',
        ]);

        $response->assertUnauthorized();
        $this->assertDatabaseMissing('blog_comments', ['body' => 'First!']);
    }

    public function test_authenticated_user_can_create_comment(): void
    {
        $user = User::factory()->create();
        $blog = Blog::factory()->create();

        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.blogs.comments.store', ['blog' => $blog->slug]), [
            'body' => 'Great write-up! Learned a lot.',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.body', 'Great write-up! Learned a lot.')
            ->assertJsonPath('data.user.id', $user->id);

        $this->assertDatabaseHas('blog_comments', [
            'blog_id' => $blog->id,
            'user_id' => $user->id,
            'body' => 'Great write-up! Learned a lot.',
        ]);
    }

    public function test_banned_user_cannot_create_comment(): void
    {
        $user = User::factory()->create(['is_banned' => true]);
        $blog = Blog::factory()->create();

        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.blogs.comments.store', ['blog' => $blog->slug]), [
            'body' => 'Trying to sneak a comment in.',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('blog_comments', ['body' => 'Trying to sneak a comment in.']);
    }

    public function test_cannot_comment_on_unpublished_blog(): void
    {
        $user = User::factory()->create();
        $blog = Blog::factory()->draft()->create();

        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.blogs.comments.store', ['blog' => $blog->slug]), [
            'body' => 'Is anyone here?',
        ]);

        $response->assertNotFound();
    }
}
