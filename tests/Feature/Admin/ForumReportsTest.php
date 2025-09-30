<?php

namespace Tests\Feature\Admin;

use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumPostReport;
use App\Models\ForumThread;
use App\Models\ForumThreadReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ForumReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'moderator', 'guard_name' => 'web']);
        Permission::create(['name' => 'forums.acp.view', 'guard_name' => 'web']);
    }

    private function createModerator(): User
    {
        $user = User::factory()->create();

        $role = Role::findByName('moderator');
        $permission = Permission::findByName('forums.acp.view');

        $role->givePermissionTo($permission);
        $user->assignRole($role);

        return $user;
    }

    private function seedForumHierarchy(User $author): ForumThread
    {
        $category = ForumCategory::create([
            'title' => 'General Discussion',
            'slug' => 'general-discussion',
            'description' => 'Community chatter',
            'position' => 1,
        ]);

        $board = ForumBoard::create([
            'forum_category_id' => $category->id,
            'title' => 'Announcements',
            'slug' => 'announcements',
            'description' => 'All announcements',
            'position' => 1,
        ]);

        return ForumThread::create([
            'forum_board_id' => $board->id,
            'user_id' => $author->id,
            'title' => 'Report me',
            'slug' => Str::slug('Report me'),
            'excerpt' => 'Thread under review',
            'is_locked' => false,
            'is_pinned' => false,
            'is_published' => true,
            'views' => 0,
        ]);
    }

    public function test_moderator_can_view_pending_reports(): void
    {
        $moderator = $this->createModerator();
        $reporter = User::factory()->create();
        $postAuthor = User::factory()->create();

        $thread = $this->seedForumHierarchy($postAuthor);

        $post = ForumPost::create([
            'forum_thread_id' => $thread->id,
            'user_id' => $postAuthor->id,
            'body' => 'This is a suspicious reply.',
        ]);

        $threadReport = ForumThreadReport::create([
            'forum_thread_id' => $thread->id,
            'reporter_id' => $reporter->id,
            'reason_category' => 'spam',
            'reason' => 'Looks like spam content.',
            'status' => ForumThreadReport::STATUS_PENDING,
        ]);

        $postReport = ForumPostReport::create([
            'forum_post_id' => $post->id,
            'reporter_id' => $reporter->id,
            'reason_category' => 'abuse',
            'reason' => 'Contains hateful language.',
            'status' => ForumPostReport::STATUS_PENDING,
        ]);

        $response = $this->actingAs($moderator)
            ->get(route('acp.forums.reports.index'));

        $response->assertStatus(200);

        $response->assertInertia(fn (Assert $page) => $page
            ->component('acp/ForumReports')
            ->where('reports.data', function ($reports) use ($threadReport, $postReport) {
                $collection = collect($reports);

                return $collection->contains(fn ($report) => $report['id'] === $threadReport->id && $report['type'] === 'thread')
                    && $collection->contains(fn ($report) => $report['id'] === $postReport->id && $report['type'] === 'post');
            })
        );
    }

    public function test_moderator_can_review_thread_report_and_lock_thread(): void
    {
        $moderator = $this->createModerator();
        $reporter = User::factory()->create();

        $thread = $this->seedForumHierarchy($reporter);

        $report = ForumThreadReport::create([
            'forum_thread_id' => $thread->id,
            'reporter_id' => $reporter->id,
            'reason_category' => 'spam',
            'status' => ForumThreadReport::STATUS_PENDING,
        ]);

        $response = $this->actingAs($moderator)
            ->from(route('acp.forums.reports.index'))
            ->patch(route('acp.forums.reports.threads.update', ['report' => $report->id]), [
                'status' => ForumThreadReport::STATUS_REVIEWED,
                'moderation_action' => 'lock_thread',
            ]);

        $response->assertRedirect(route('acp.forums.reports.index'));
        $response->assertSessionHas('success', 'Thread report updated.');

        $report->refresh();
        $thread->refresh();

        $this->assertSame(ForumThreadReport::STATUS_REVIEWED, $report->status);
        $this->assertNotNull($report->reviewed_at);
        $this->assertSame($moderator->id, $report->reviewed_by);
        $this->assertTrue($thread->is_locked);
    }

    public function test_moderator_can_dismiss_post_report_and_delete_post(): void
    {
        $moderator = $this->createModerator();
        $reporter = User::factory()->create();
        $postAuthor = User::factory()->create();

        $thread = $this->seedForumHierarchy($reporter);

        $post = ForumPost::create([
            'forum_thread_id' => $thread->id,
            'user_id' => $postAuthor->id,
            'body' => 'A questionable post needing review.',
        ]);

        $report = ForumPostReport::create([
            'forum_post_id' => $post->id,
            'reporter_id' => $reporter->id,
            'reason_category' => 'abuse',
            'status' => ForumPostReport::STATUS_PENDING,
        ]);

        $response = $this->actingAs($moderator)
            ->from(route('acp.forums.reports.index'))
            ->patch(route('acp.forums.reports.posts.update', ['report' => $report->id]), [
                'status' => ForumPostReport::STATUS_DISMISSED,
                'moderation_action' => 'delete_post',
            ]);

        $response->assertRedirect(route('acp.forums.reports.index'));
        $response->assertSessionHas('success', 'Post report updated.');

        $report->refresh();

        $this->assertSame(ForumPostReport::STATUS_DISMISSED, $report->status);
        $this->assertNotNull($report->reviewed_at);
        $this->assertSame($moderator->id, $report->reviewed_by);
        $this->assertSoftDeleted('forum_posts', [
            'id' => $post->id,
        ]);
    }
}
