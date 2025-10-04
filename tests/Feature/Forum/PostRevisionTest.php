<?php

namespace Tests\Feature\Forum;

use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumPostRevision;
use App\Models\ForumThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PostRevisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'moderator', 'guard_name' => 'web']);
        Role::create(['name' => 'admin', 'guard_name' => 'web']);
        Role::create(['name' => 'editor', 'guard_name' => 'web']);
    }

    public function test_updating_post_creates_revision_snapshot(): void
    {
        $author = User::factory()->create();
        [$board, $thread, $post] = $this->createForumContext($author, '<p>Original content</p>');

        $response = $this->actingAs($author)->put(route('forum.posts.update', [$board, $thread, $post]), [
            'body' => '<p>Updated content</p>',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('forum_post_revisions', [
            'forum_post_id' => $post->id,
            'body' => '<p>Original content</p>',
            'edited_by_id' => $author->id,
        ]);

        $post->refresh();

        $this->assertSame('<p>Updated content</p>', $post->body);
        $this->assertNotNull($post->edited_at);
    }

    public function test_moderator_can_view_revision_history(): void
    {
        $author = User::factory()->create();
        [$board, $thread, $post] = $this->createForumContext($author, '<p>First version</p>');

        $this->actingAs($author)->put(route('forum.posts.update', [$board, $thread, $post]), [
            'body' => '<p>Second version</p>',
        ]);

        $moderator = $this->createModerator();

        $response = $this->actingAs($moderator)->get(route('forum.posts.history', [$board, $thread, $post]));

        $response->assertOk();

        $response->assertInertia(function (AssertableInertia $page) use ($post, $author) {
            return $page
                ->component('acp/ForumPostHistory')
                ->where('post.id', $post->id)
                ->where('revisions.0.body', '<p>First version</p>')
                ->where('revisions.0.editor.id', $author->id);
        });
    }

    public function test_non_author_cannot_view_revision_history(): void
    {
        $author = User::factory()->create();
        [$board, $thread, $post] = $this->createForumContext($author, '<p>Initial post</p>');

        $this->actingAs($author)->put(route('forum.posts.update', [$board, $thread, $post]), [
            'body' => '<p>Changed content</p>',
        ]);

        $viewer = User::factory()->create();

        $response = $this->actingAs($viewer)->get(route('forum.posts.history', [$board, $thread, $post]));

        $response->assertForbidden();
    }

    public function test_author_can_restore_revision(): void
    {
        $author = User::factory()->create();
        [$board, $thread, $post] = $this->createForumContext($author, '<p>Original body</p>');

        $this->actingAs($author)->put(route('forum.posts.update', [$board, $thread, $post]), [
            'body' => '<p>Edited body</p>',
        ]);

        $revision = ForumPostRevision::first();
        $this->assertNotNull($revision);

        $response = $this->actingAs($author)->put(route('forum.posts.history.restore', [$board, $thread, $post, $revision]));

        $response->assertRedirect(route('forum.posts.history', [$board, $thread, $post]));
        $response->assertSessionHas('success', 'Revision restored successfully.');

        $post->refresh();

        $this->assertSame('<p>Original body</p>', $post->body);
        $this->assertNotNull($post->edited_at);

        $this->assertDatabaseHas('forum_post_revisions', [
            'forum_post_id' => $post->id,
            'body' => '<p>Edited body</p>',
        ]);

        $this->assertSame(2, ForumPostRevision::query()->where('forum_post_id', $post->id)->count());
    }

    public function test_unauthorized_user_cannot_restore_revision(): void
    {
        $author = User::factory()->create();
        [$board, $thread, $post] = $this->createForumContext($author, '<p>Original</p>');

        $this->actingAs($author)->put(route('forum.posts.update', [$board, $thread, $post]), [
            'body' => '<p>Modified</p>',
        ]);

        $revision = ForumPostRevision::firstOrFail();

        $otherUser = User::factory()->create();

        $response = $this->actingAs($otherUser)->put(route('forum.posts.history.restore', [$board, $thread, $post, $revision]));

        $response->assertForbidden();
    }

    private function createForumContext(User $author, string $body): array
    {
        $category = ForumCategory::create([
            'title' => 'General Discussion',
            'slug' => 'general-discussion',
            'description' => 'General chatter',
            'position' => 1,
        ]);

        $board = ForumBoard::create([
            'forum_category_id' => $category->id,
            'title' => 'Announcements',
            'slug' => 'announcements',
            'description' => 'All announcements',
            'position' => 1,
        ]);

        $thread = ForumThread::create([
            'forum_board_id' => $board->id,
            'user_id' => $author->id,
            'title' => 'Thread Title',
            'slug' => Str::slug('Thread Title-' . Str::random(5)),
            'excerpt' => 'Summary',
            'is_locked' => false,
            'is_pinned' => false,
            'is_published' => true,
            'views' => 0,
            'last_posted_at' => now(),
            'last_post_user_id' => $author->id,
        ]);

        $post = ForumPost::create([
            'forum_thread_id' => $thread->id,
            'user_id' => $author->id,
            'body' => $body,
        ]);

        return [$board, $thread, $post];
    }

    private function createModerator(): User
    {
        $user = User::factory()->create();

        $role = Role::findByName('moderator');
        $user->assignRole($role);

        return $user;
    }
}
