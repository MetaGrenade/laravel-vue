<?php

namespace Tests\Feature\Api;

use App\Models\Blog;
use App\Models\BlogComment;
use App\Models\BlogCommentReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BlogApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'moderator', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'editor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    }

    public function test_can_list_only_approved_comments_for_published_blog(): void
    {
        $blog = Blog::factory()->published()->create();

        $approved = BlogComment::factory()->for($blog)->create([
            'status' => BlogComment::STATUS_APPROVED,
        ]);
        BlogComment::factory()->for($blog)->create([
            'status' => BlogComment::STATUS_PENDING,
        ]);
        BlogComment::factory()->for($blog)->create([
            'status' => BlogComment::STATUS_REJECTED,
        ]);

        $response = $this->getJson(route('api.v1.blogs.comments.index', $blog));

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $approved->id);
    }

    public function test_comments_can_be_sorted_by_oldest_or_newest(): void
    {
        $blog = Blog::factory()->published()->create();

        $oldest = BlogComment::factory()->for($blog)->create([
            'status' => BlogComment::STATUS_APPROVED,
            'created_at' => now()->subDays(3),
        ]);

        $middle = BlogComment::factory()->for($blog)->create([
            'status' => BlogComment::STATUS_APPROVED,
            'created_at' => now()->subDay(),
        ]);

        $newest = BlogComment::factory()->for($blog)->create([
            'status' => BlogComment::STATUS_APPROVED,
            'created_at' => now(),
        ]);

        $defaultResponse = $this->getJson(route('api.v1.blogs.comments.index', $blog));

        $defaultResponse
            ->assertOk()
            ->assertJsonPath('data.0.id', $oldest->id)
            ->assertJsonPath('data.1.id', $middle->id)
            ->assertJsonPath('data.2.id', $newest->id);

        $newestFirstResponse = $this->getJson(
            route('api.v1.blogs.comments.index', ['blog' => $blog, 'sort' => 'newest'])
        );

        $newestFirstResponse
            ->assertOk()
            ->assertJsonPath('data.0.id', $newest->id)
            ->assertJsonPath('data.1.id', $middle->id)
            ->assertJsonPath('data.2.id', $oldest->id);
    }

    public function test_authenticated_user_can_create_comment_on_published_blog(): void
    {
        $blog = Blog::factory()->published()->create([
            'comments_enabled' => true,
        ]);
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $payload = ['body' => ' Nice new comment '];

        $response = $this->postJson(route('api.v1.blogs.comments.store', $blog), $payload);

        $response
            ->assertCreated()
            ->assertJsonPath('body', 'Nice new comment');

        $this->assertDatabaseHas('blog_comments', [
            'blog_id' => $blog->id,
            'user_id' => $user->id,
            'body' => 'Nice new comment',
            'status' => BlogComment::STATUS_APPROVED,
        ]);
    }

    public function test_comment_creation_is_blocked_when_comments_disabled(): void
    {
        $blog = Blog::factory()->published()->create([
            'comments_enabled' => false,
        ]);
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->postJson(route('api.v1.blogs.comments.store', $blog), ['body' => 'Comment'])
            ->assertForbidden();
    }

    public function test_author_can_update_their_comment(): void
    {
        $user = User::factory()->create();
        $blog = Blog::factory()->published()->create();
        $comment = BlogComment::factory()->for($blog)->for($user)->create([
            'body' => 'Original text',
        ]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->patchJson(
            route('api.v1.blogs.comments.update', [$blog, $comment]),
            ['body' => 'Updated via API']
        );

        $response
            ->assertOk()
            ->assertJsonPath('body', 'Updated via API');

        $this->assertDatabaseHas('blog_comments', [
            'id' => $comment->id,
            'body' => 'Updated via API',
        ]);
    }

    public function test_moderator_can_delete_comment(): void
    {
        $moderator = User::factory()->create();
        $moderator->assignRole('moderator');

        $blog = Blog::factory()->published()->create();
        $comment = BlogComment::factory()->for($blog)->create();

        Sanctum::actingAs($moderator, ['*']);

        $response = $this->deleteJson(route('api.v1.blogs.comments.destroy', [$blog, $comment]));

        $response
            ->assertOk()
            ->assertJsonPath('id', $comment->id);

        $this->assertDatabaseMissing('blog_comments', [
            'id' => $comment->id,
        ]);
    }

    public function test_verified_user_can_report_other_users_comment(): void
    {
        $blog = Blog::factory()->published()->create();
        $commentAuthor = User::factory()->create();
        $comment = BlogComment::factory()->for($blog)->for($commentAuthor)->create([
            'is_flagged' => false,
        ]);

        $reporter = User::factory()->create(['email_verified_at' => now()]);
        Sanctum::actingAs($reporter, ['*']);

        $payload = [
            'reason_category' => 'spam',
            'reason' => 'Looks like spam',
            'evidence_url' => 'https://example.com/evidence',
        ];

        $response = $this->postJson(
            route('api.v1.blogs.comments.report', [$blog, $comment]),
            $payload
        );

        $response
            ->assertCreated()
            ->assertJsonPath('message', 'Report submitted to the moderation team.');

        $this->assertDatabaseHas('blog_comment_reports', [
            'blog_comment_id' => $comment->id,
            'reporter_id' => $reporter->id,
            'reason_category' => 'spam',
            'reason' => 'Looks like spam',
            'evidence_url' => 'https://example.com/evidence',
            'status' => BlogCommentReport::STATUS_PENDING,
        ]);

        $this->assertTrue($comment->fresh()->is_flagged);
    }

    public function test_unverified_user_cannot_report_comment(): void
    {
        $blog = Blog::factory()->published()->create();
        $comment = BlogComment::factory()->for($blog)->create();

        $reporter = User::factory()->unverified()->create();
        Sanctum::actingAs($reporter, ['*']);

        $this->postJson(
            route('api.v1.blogs.comments.report', [$blog, $comment]),
            ['reason_category' => 'spam']
        )->assertForbidden();
    }

    public function test_user_can_subscribe_and_unsubscribe_from_blog_comments(): void
    {
        $blog = Blog::factory()->published()->create();
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $subscribeResponse = $this->postJson(route('api.v1.blogs.comments.subscriptions.store', $blog));

        $subscribeResponse
            ->assertOk()
            ->assertJsonPath('subscribed', true)
            ->assertJsonPath('subscribers_count', 1);

        $this->assertDatabaseHas('blog_comment_subscriptions', [
            'blog_id' => $blog->id,
            'user_id' => $user->id,
        ]);

        $unsubscribeResponse = $this->deleteJson(route('api.v1.blogs.comments.subscriptions.destroy', $blog));

        $unsubscribeResponse
            ->assertOk()
            ->assertJsonPath('subscribed', false)
            ->assertJsonPath('subscribers_count', 0);

        $this->assertDatabaseMissing('blog_comment_subscriptions', [
            'blog_id' => $blog->id,
            'user_id' => $user->id,
        ]);
    }
}
