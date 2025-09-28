<?php

namespace Tests\Feature;

use App\Http\Controllers\BlogController;
use App\Models\Blog;
use App\Models\BlogComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BlogCommentTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_can_view_comments(): void
    {
        $blog = Blog::factory()->create();
        BlogComment::factory()->count(3)->create(['blog_id' => $blog->id]);

        $response = $this->get(route('blogs.view', ['blog' => $blog->slug]));

        $response->assertOk()->assertInertia(
            fn (Assert $page) => $page
                ->component('BlogView')
                ->has('comments.data', 3)
                ->where('comments.meta.total', 3)
        );
    }

    public function test_guest_cannot_create_comment(): void
    {
        $blog = Blog::factory()->create();

        $response = $this->post(route('blogs.comments.store', ['blog' => $blog->slug]), [
            'body' => 'First!',
        ]);

        $response->assertRedirect(route('login'));
        $this->assertDatabaseMissing('blog_comments', ['body' => 'First!']);
    }

    public function test_authenticated_user_can_create_comment(): void
    {
        $user = User::factory()->create();
        $blog = Blog::factory()->create();
        BlogComment::factory()->count(BlogController::COMMENTS_PER_PAGE)->create(['blog_id' => $blog->id]);

        $this->actingAs($user);

        $response = $this->post(route('blogs.comments.store', ['blog' => $blog->slug]), [
            'body' => 'Great write-up! Learned a lot.',
        ]);

        $expectedPage = (int) ceil((BlogController::COMMENTS_PER_PAGE + 1) / BlogController::COMMENTS_PER_PAGE);

        $response->assertRedirect(route('blogs.view', ['blog' => $blog->slug, 'page' => $expectedPage]));

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

        $this->actingAs($user);

        $response = $this->post(route('blogs.comments.store', ['blog' => $blog->slug]), [
            'body' => 'Trying to sneak a comment in.',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('blog_comments', ['body' => 'Trying to sneak a comment in.']);
    }

    public function test_cannot_comment_on_unpublished_blog(): void
    {
        $user = User::factory()->create();
        $blog = Blog::factory()->draft()->create();

        $this->actingAs($user);

        $response = $this->post(route('blogs.comments.store', ['blog' => $blog->slug]), [
            'body' => 'Is anyone here?',
        ]);

        $response->assertNotFound();
    }
}
