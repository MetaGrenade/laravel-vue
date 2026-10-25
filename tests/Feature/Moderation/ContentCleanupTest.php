<?php

namespace Tests\Feature\Moderation;

use App\Enums\ReviewStatus;
use App\Jobs\GenerateUserDataExport;
use App\Models\AuditLog;
use App\Models\Blog;
use App\Models\BlogComment;
use App\Models\BlogCommentReport;
use App\Models\DataExport;
use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumPostReport;
use App\Models\ForumThread;
use App\Models\ForumThreadReport;
use App\Models\ModerationAction;
use App\Models\ProductReview;
use App\Models\User;
use App\Support\Forum\ForumIndexCache;
use App\Support\Moderation\MemberContent;
use App\Support\Moderation\Moderation;
use App\Support\Moderation\ModerationRefused;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Taking down what a member posted (docs/moderation.md): what comes down and what is left alone, that nothing is destroyed that a
 * moderator could want back, that the member is not told, and who may do it.
 */
class ContentCleanupTest extends TestCase
{
    use RefreshDatabase;

    private ForumBoard $board;

    protected function setUp(): void
    {
        parent::setUp();

        // The roles and permissions the way a site has them, so that what a moderator can do is what the seeder gives them.
        $this->seed(RolePermissionSeeder::class);
        Notification::fake();

        $category = ForumCategory::create(['title' => 'General', 'slug' => 'general', 'description' => 'Chat', 'position' => 1]);
        $this->board = ForumBoard::create(['forum_category_id' => $category->id, 'title' => 'The lounge', 'slug' => 'the-lounge', 'description' => 'Chat', 'position' => 1]);
    }

    private function as(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function member(): User
    {
        return User::factory()->create();
    }

    private function content(): MemberContent
    {
        return app(MemberContent::class);
    }

    /**
     * @param  array<string, mixed>  $over
     */
    private function thread(User $author, string $title = 'A thread', array $over = []): ForumThread
    {
        $thread = new ForumThread([
            'forum_board_id' => $this->board->id,
            'user_id' => $author->id,
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::random(5),
            'excerpt' => $title,
            'is_locked' => false,
            'is_pinned' => false,
            'is_published' => true,
            'views' => 0,
            'last_posted_at' => now(),
            'last_post_user_id' => $author->id,
        ]);
        $thread->forceFill($over)->save();

        return $thread;
    }

    /**
     * @param  array<string, mixed>  $over
     */
    private function reply(ForumThread $thread, User $author, array $over = []): ForumPost
    {
        $post = new ForumPost(['forum_thread_id' => $thread->id, 'user_id' => $author->id, 'body' => '<p>Buy cheap watches</p>']);
        $post->forceFill($over)->save();

        return $post;
    }

    private function held(): array
    {
        return ['held_at' => now(), 'held_reason' => 'Contains "watches"'];
    }

    private function comment(User $author, string $status): BlogComment
    {
        return BlogComment::factory()->for(Blog::factory()->create())->for($author)->create(['status' => $status]);
    }

    private function review(User $author, ReviewStatus $status): ProductReview
    {
        return ProductReview::factory()->create(['user_id' => $author->id, 'status' => $status]);
    }

    private function reply_trashed(int $id): ?ForumPost
    {
        return ForumPost::query()->withoutGlobalScope(ForumPost::VISIBLE)->withTrashed()->find($id);
    }

    /**
     * A spammer with something of every kind in every state, among other people's things.
     *
     * @return array<string, mixed>
     */
    private function spamWave(): array
    {
        $spammer = $this->member();
        $other = $this->member();

        $own = $this->thread($spammer, 'Cheap watches');
        $ownOpening = $this->reply($own, $spammer);
        $othersReplyToOwn = $this->reply($own, $other);
        $ownReplyToOwn = $this->reply($own, $spammer);

        $heldThread = $this->thread($spammer, 'Held watches', ['is_published' => false, 'held_at' => now()]);
        $heldThreadPost = $this->reply($heldThread, $spammer);

        $theirs = $this->thread($other, 'A question');
        $othersPost = $this->reply($theirs, $other, ['body' => '<p>How do I reset it?</p>']);
        $spamReply = $this->reply($theirs, $spammer);
        $heldReply = $this->reply($theirs, $spammer, $this->held());

        return [
            'spammer' => $spammer,
            'other' => $other,
            'own' => $own,
            'ownOpening' => $ownOpening,
            'othersReplyToOwn' => $othersReplyToOwn,
            'ownReplyToOwn' => $ownReplyToOwn,
            'heldThread' => $heldThread,
            'heldThreadPost' => $heldThreadPost,
            'theirs' => $theirs,
            'othersPost' => $othersPost,
            'spamReply' => $spamReply,
            'heldReply' => $heldReply,
            'approvedComment' => $this->comment($spammer, BlogComment::STATUS_APPROVED),
            'pendingComment' => $this->comment($spammer, BlogComment::STATUS_PENDING),
            'publishedReview' => $this->review($spammer, ReviewStatus::Published),
            'pendingReview' => $this->review($spammer, ReviewStatus::Pending),
        ];
    }

    // --- What comes down ----------------------------------------------------------------------------------------------------------

    #[Test]
    public function everything_a_member_posted_that_is_showing_comes_down_and_is_counted(): void
    {
        $wave = $this->spamWave();
        $moderator = $this->as('moderator');

        $taken = $this->content()->takeDown($wave['spammer'], $moderator, 'Spam wave', 'Seen on three boards');

        $this->assertSame(
            ['threads_hidden' => 1, 'threads_rejected' => 1, 'replies' => 2, 'comments' => 2, 'reviews' => 2, 'reports' => 0],
            $taken->toArray(),
        );

        $this->assertFalse($wave['own']->fresh()->is_published, 'a thread they started is unpublished');
        $this->assertNull(ForumThread::query()->find($wave['heldThread']->id), 'a thread waiting for review is rejected, as the queue does');
        $this->assertNull($this->reply_trashed($wave['heldThreadPost']->id), 'and its post goes with it');

        $this->assertTrue($this->reply_trashed($wave['spamReply']->id)->trashed(), 'their reply in somebody else\'s thread is deleted');
        $this->assertTrue($this->reply_trashed($wave['heldReply']->id)->trashed(), 'a held reply of theirs too');
        $this->assertNotNull($this->reply_trashed($wave['heldReply']->id)->held_at, 'it is still marked as held');

        $this->assertSame(BlogComment::STATUS_REJECTED, $wave['approvedComment']->fresh()->status);
        $this->assertSame(BlogComment::STATUS_REJECTED, $wave['pendingComment']->fresh()->status);

        foreach (['publishedReview', 'pendingReview'] as $key) {
            $review = $wave[$key]->fresh();
            $this->assertSame(ReviewStatus::Rejected, $review->status);
            $this->assertSame($moderator->id, $review->moderated_by);
            $this->assertNotNull($review->moderated_at);
        }

        $this->assertSame(['threads' => 0, 'replies' => 0, 'comments' => 0, 'reviews' => 0], $this->content()->showing($wave['spammer']));
    }

    #[Test]
    public function the_replies_in_a_thread_they_started_go_out_of_sight_with_it_and_are_not_deleted(): void
    {
        $wave = $this->spamWave();

        $this->content()->takeDown($wave['spammer'], $this->as('moderator'), 'Spam wave');

        foreach (['ownOpening', 'othersReplyToOwn', 'ownReplyToOwn'] as $key) {
            $this->assertFalse($this->reply_trashed($wave[$key]->id)->trashed(), "{$key} is hidden with its thread, not deleted");
        }
    }

    #[Test]
    public function what_is_not_theirs_is_left_alone(): void
    {
        $wave = $this->spamWave();
        $other = $wave['other'];
        $ownThread = $this->thread($other, 'Another question');
        $comment = $this->comment($other, BlogComment::STATUS_APPROVED);
        $review = $this->review($other, ReviewStatus::Published);
        $staff = $this->as('editor');
        $staffThread = $this->thread($staff, 'Welcome');

        $this->content()->takeDown($wave['spammer'], $this->as('moderator'), 'Spam wave');

        $this->assertTrue($wave['theirs']->fresh()->is_published);
        $this->assertFalse($this->reply_trashed($wave['othersPost']->id)->trashed());
        $this->assertTrue($ownThread->fresh()->is_published);
        $this->assertSame(BlogComment::STATUS_APPROVED, $comment->fresh()->status);
        $this->assertSame(ReviewStatus::Published, $review->fresh()->status);
        $this->assertNull($review->fresh()->moderated_by);
        $this->assertTrue($staffThread->fresh()->is_published);
    }

    #[Test]
    public function what_was_already_down_is_left_as_it_was(): void
    {
        $this->freezeTime();
        $spammer = $this->member();
        $theirs = $this->thread($this->member(), 'A question');
        $earlier = $this->reply($theirs, $spammer);
        $earlier->delete();
        $hidden = $this->thread($spammer, 'Hidden earlier', ['is_published' => false]);
        $hiddenPost = $this->reply($hidden, $spammer);
        $rejectedComment = $this->comment($spammer, BlogComment::STATUS_REJECTED);
        $rejectedReview = ProductReview::factory()->rejected()->create(['user_id' => $spammer->id]);

        $deletedAt = $this->reply_trashed($earlier->id)->deleted_at;
        $this->travel(2)->hours();

        $taken = $this->content()->takeDown($spammer, $this->as('moderator'), 'Spam wave');

        $this->assertTrue($taken->isEmpty());
        $this->assertTrue($this->reply_trashed($earlier->id)->deleted_at->equalTo($deletedAt), 'a reply deleted earlier keeps the time it was deleted');
        $this->assertFalse($hidden->fresh()->is_published);
        $this->assertFalse($this->reply_trashed($hiddenPost->id)->trashed());
        $this->assertSame(BlogComment::STATUS_REJECTED, $rejectedComment->fresh()->status);
        $this->assertNull($rejectedReview->fresh()->moderated_by, 'a review rejected earlier is not touched');
    }

    #[Test]
    public function a_member_with_nothing_showing_leaves_nothing_in_the_history(): void
    {
        $member = $this->member();

        $taken = $this->content()->takeDown($member, $this->as('moderator'), 'Spam wave');

        $this->assertTrue($taken->isEmpty());
        $this->assertSame(0, ModerationAction::query()->count());
    }

    #[Test]
    public function doing_it_twice_does_it_once(): void
    {
        $wave = $this->spamWave();
        $moderator = $this->as('moderator');

        $first = $this->content()->takeDown($wave['spammer'], $moderator, 'Spam wave');
        $second = $this->content()->takeDown($wave['spammer'], $moderator, 'Spam wave again');

        $this->assertFalse($first->isEmpty());
        $this->assertTrue($second->isEmpty());
        $this->assertSame(1, ModerationAction::query()->where('type', ModerationAction::REMOVAL)->count());
    }

    #[Test]
    public function showing_counts_what_would_come_down_and_nothing_else(): void
    {
        $wave = $this->spamWave();
        $spammer = $wave['spammer'];
        $this->thread($spammer, 'Hidden earlier', ['is_published' => false]);
        $this->comment($spammer, BlogComment::STATUS_REJECTED);
        ProductReview::factory()->rejected()->create(['user_id' => $spammer->id]);
        $this->reply($wave['theirs'], $spammer)->delete();

        $this->assertSame(
            ['threads' => 2, 'replies' => 2, 'comments' => 2, 'reviews' => 2],
            $this->content()->showing($spammer),
            'a published thread and one waiting for review; a reply and a held one; not what is already down, and not their replies in their own threads',
        );
        $this->assertSame(
            ['threads' => 1, 'replies' => 1, 'comments' => 0, 'reviews' => 0],
            $this->content()->showing($wave['other']),
            'their own thread, and their reply in the thread the spammer started',
        );
    }

    // --- It can be put back --------------------------------------------------------------------------------------------------------

    #[Test]
    public function a_thread_that_was_hidden_is_out_of_the_public_pages_and_comes_back_when_it_is_published_again(): void
    {
        $wave = $this->spamWave();
        $own = $wave['own'];

        $this->get(route('forum.threads.show', [$this->board, $own]))->assertOk();

        $this->content()->takeDown($wave['spammer'], $this->as('moderator'), 'Spam wave');

        $this->get(route('forum.threads.show', [$this->board, $own]))->assertNotFound();
        $listed = collect($this->get(route('forum.boards.show', ['board' => $this->board->slug]))->viewData('page')['props']['threads']['data'])->pluck('id')->all();
        $this->assertNotContains($own->id, $listed);
        $this->assertContains($wave['theirs']->id, $listed);
        $found = collect($this->get(route('forum.boards.show', ['board' => $this->board->slug, 'search' => 'watches']))->viewData('page')['props']['threads']['data']);
        $this->assertCount(0, $found, 'not found by searching either');

        // What the thread page of a moderator offers: publish it again, and the replies other people made are there.
        $own->fresh()->forceFill(['is_published' => true])->save();
        $this->get(route('forum.threads.show', [$this->board, $own]))->assertOk();
        $this->assertSame(3, $own->posts()->count());
    }

    #[Test]
    public function their_replies_are_not_on_the_thread_page_and_the_forum_front_page_forgets_them(): void
    {
        // Only a reply: a thread waiting for review is deleted through a model event, which would empty the cache by itself.
        $spammer = $this->member();
        $other = $this->member();
        $theirs = $this->thread($other, 'A question');
        $othersPost = $this->reply($theirs, $other, ['body' => '<p>How do I reset it?</p>']);
        $spamReply = $this->reply($theirs, $spammer);
        $cache = app(ForumIndexCache::class);
        $this->assertContains($spamReply->id, $cache->latestPosts()->pluck('id')->all(), 'it is the newest post, and cached');

        $this->content()->takeDown($spammer, $this->as('moderator'), 'Spam wave');

        $this->assertNotContains($spamReply->id, $cache->latestPosts()->pluck('id')->all(), 'the cache was emptied');
        $posts = collect($this->get(route('forum.threads.show', [$this->board, $theirs]))->viewData('page')['props']['posts']['data']);
        $this->assertSame([$othersPost->id], $posts->pluck('id')->all());
    }

    #[Test]
    public function a_comment_that_came_down_is_not_on_the_post_and_does_not_make_it_popular(): void
    {
        $spammer = $this->member();
        $reader = $this->member();
        $quiet = Blog::factory()->published()->create(['title' => 'Quiet post', 'published_at' => now()->subDay()]);
        $busy = Blog::factory()->published()->create(['title' => 'Busy post', 'published_at' => now()->subDays(2)]);
        BlogComment::factory()->for($quiet)->for($reader)->create(['body' => 'Nice', 'status' => BlogComment::STATUS_APPROVED]);

        foreach (range(1, 3) as $number) {
            BlogComment::factory()->for($busy)->for($spammer)->create(['body' => "Advert {$number}", 'status' => BlogComment::STATUS_APPROVED]);
        }

        BlogComment::factory()->for($busy)->for($reader)->create(['body' => 'A real comment', 'status' => BlogComment::STATUS_APPROVED]);
        BlogComment::factory()->for($busy)->for($reader)->create(['body' => 'Not yet approved', 'status' => BlogComment::STATUS_PENDING]);

        $popular = fn () => collect($this->get(route('blogs.index', ['sort' => 'popular']))->viewData('page')['props']['blogs']['data'])->pluck('title')->all();
        $shown = fn () => collect($this->get(route('blogs.view', ['slug' => $busy->slug]))->viewData('page')['props']['comments']['data'])->pluck('body')->all();

        $this->assertSame(['Busy post', 'Quiet post'], $popular(), 'four comments against one');
        $this->assertSame(['Advert 1', 'Advert 2', 'Advert 3', 'A real comment'], $shown(), 'one that is still waiting is not shown');

        $this->content()->takeDown($spammer, $this->as('moderator'), 'Spam wave');

        $this->assertSame(['A real comment'], $shown(), 'a rejected comment is not on the post');
        $this->assertSame(['Quiet post', 'Busy post'], $popular(), 'one each now, and the newer first');
    }

    #[Test]
    public function rejected_comments_do_not_decide_what_is_recommended_on_a_post_or_on_the_dashboard(): void
    {
        $spammer = $this->member();
        $reader = $this->member();
        $reading = Blog::factory()->published()->create(['title' => 'The post being read']);
        $liked = Blog::factory()->published()->create(['title' => 'Liked', 'published_at' => now()->subDays(3)]);
        $inflated = Blog::factory()->published()->create(['title' => 'Inflated', 'published_at' => now()->subDays(4)]);
        BlogComment::factory()->for($liked)->for($reader)->create(['status' => BlogComment::STATUS_APPROVED]);

        foreach (range(1, 3) as $ignored) {
            BlogComment::factory()->for($inflated)->for($spammer)->create(['status' => BlogComment::STATUS_APPROVED]);
        }

        $recommended = fn () => collect($this->get(route('blogs.view', ['slug' => $reading->slug]))->viewData('page')['props']['blog']['recommendations'])->pluck('title')->all();
        $onDashboard = fn () => collect($this->actingAs($reader)->get(route('dashboard'))->viewData('page')['props']['recommendedArticles'])->pluck('title')->all();

        // The dashboard leaves out what the reader has opened, so it goes first; opening the post to see its recommendations comes after.
        $this->assertSame(['Inflated', 'Liked'], array_slice($onDashboard(), 0, 2), 'three comments beat one');
        $this->assertSame(['Inflated', 'Liked'], $recommended());

        $this->content()->takeDown($spammer, $this->as('moderator'), 'Spam wave');

        $this->assertSame(['Liked', 'Inflated'], array_slice($onDashboard(), 0, 2));
        $this->assertSame(['Liked', 'Inflated'], $recommended());
    }

    // --- The paperwork -------------------------------------------------------------------------------------------------------------

    #[Test]
    public function the_reports_about_what_came_down_are_closed_and_no_others(): void
    {
        $wave = $this->spamWave();
        $moderator = $this->as('moderator');
        $reporter = $this->member();

        $postReport = ForumPostReport::create(['forum_post_id' => $wave['spamReply']->id, 'reporter_id' => $reporter->id, 'reason_category' => 'spam', 'reason' => 'Advert', 'status' => ForumPostReport::STATUS_PENDING]);
        $dismissed = ForumPostReport::create(['forum_post_id' => $wave['heldReply']->id, 'reporter_id' => $reporter->id, 'reason_category' => 'spam', 'reason' => 'Advert', 'status' => ForumPostReport::STATUS_DISMISSED]);
        $othersReport = ForumPostReport::create(['forum_post_id' => $wave['othersPost']->id, 'reporter_id' => $reporter->id, 'reason_category' => 'other', 'reason' => 'Rude', 'status' => ForumPostReport::STATUS_PENDING]);
        $threadReport = ForumThreadReport::create(['forum_thread_id' => $wave['own']->id, 'reporter_id' => $reporter->id, 'reason_category' => 'spam', 'reason' => 'Advert', 'status' => ForumThreadReport::STATUS_PENDING]);
        $othersThreadReport = ForumThreadReport::create(['forum_thread_id' => $wave['theirs']->id, 'reporter_id' => $reporter->id, 'reason_category' => 'other', 'reason' => 'Off topic', 'status' => ForumThreadReport::STATUS_PENDING]);
        $commentReport = BlogCommentReport::create(['blog_comment_id' => $wave['approvedComment']->id, 'reporter_id' => $reporter->id, 'reason_category' => 'spam', 'reason' => 'Advert', 'status' => BlogCommentReport::STATUS_PENDING]);

        // Reports about what is already down, and is somebody else's, wait for whoever deals with them.
        $strangersThread = $this->thread($wave['other'], 'Hidden by a moderator', ['is_published' => false]);
        $strangersPost = $this->reply($wave['theirs'], $wave['other']);
        $strangersPost->delete();
        $strangersComment = $this->comment($wave['other'], BlogComment::STATUS_REJECTED);
        $others = [
            ForumThreadReport::create(['forum_thread_id' => $strangersThread->id, 'reporter_id' => $reporter->id, 'reason_category' => 'other', 'reason' => 'Odd', 'status' => ForumThreadReport::STATUS_PENDING]),
            ForumPostReport::create(['forum_post_id' => $strangersPost->id, 'reporter_id' => $reporter->id, 'reason_category' => 'other', 'reason' => 'Odd', 'status' => ForumPostReport::STATUS_PENDING]),
            BlogCommentReport::create(['blog_comment_id' => $strangersComment->id, 'reporter_id' => $reporter->id, 'reason_category' => 'other', 'reason' => 'Odd', 'status' => BlogCommentReport::STATUS_PENDING]),
        ];

        $taken = $this->content()->takeDown($wave['spammer'], $moderator, 'Spam wave');

        $this->assertSame(3, $taken->reports);

        foreach ([$postReport, $threadReport, $commentReport] as $report) {
            $report->refresh();
            $this->assertSame('reviewed', $report->status);
            $this->assertSame($moderator->id, $report->reviewed_by);
            $this->assertNotNull($report->reviewed_at);
        }

        $this->assertSame('dismissed', $dismissed->fresh()->status, 'a decision already made stays');
        $this->assertSame('pending', $othersReport->fresh()->status, 'a report about somebody else\'s post stays');
        $this->assertSame('pending', $othersThreadReport->fresh()->status);

        foreach ($others as $report) {
            $this->assertSame('pending', $report->fresh()->status, get_class($report));
        }
    }

    #[Test]
    public function reports_about_what_was_already_down_are_not_touched_when_nothing_more_comes_down(): void
    {
        $spammer = $this->member();
        $post = $this->reply($this->thread($this->member(), 'A question'), $spammer);
        $report = ForumPostReport::create(['forum_post_id' => $post->id, 'reporter_id' => $this->member()->id, 'reason_category' => 'spam', 'reason' => 'Advert', 'status' => ForumPostReport::STATUS_PENDING]);
        $post->delete();

        $this->content()->takeDown($spammer, $this->as('moderator'), 'Spam wave');

        $this->assertSame('pending', $report->fresh()->status);
    }

    #[Test]
    public function reports_about_what_was_already_down_stay_open_when_something_else_comes_down(): void
    {
        $this->freezeTime();
        $spammer = $this->member();
        $other = $this->member();
        $moderator = $this->as('moderator');
        $reporter = $this->member();

        $oldThread = $this->thread($spammer, 'Hidden earlier', ['is_published' => false]);
        $oldReply = $this->reply($this->thread($other, 'An old question'), $spammer);
        $oldReply->delete();
        $oldComment = $this->comment($spammer, BlogComment::STATUS_REJECTED);
        $deletedAt = $this->reply_trashed($oldReply->id)->deleted_at;

        $oldReports = [
            ForumThreadReport::create(['forum_thread_id' => $oldThread->id, 'reporter_id' => $reporter->id, 'reason_category' => 'other', 'reason' => 'Odd', 'status' => ForumThreadReport::STATUS_PENDING]),
            ForumPostReport::create(['forum_post_id' => $oldReply->id, 'reporter_id' => $reporter->id, 'reason_category' => 'spam', 'reason' => 'Advert', 'status' => ForumPostReport::STATUS_PENDING]),
            BlogCommentReport::create(['blog_comment_id' => $oldComment->id, 'reporter_id' => $reporter->id, 'reason_category' => 'other', 'reason' => 'Odd', 'status' => BlogCommentReport::STATUS_PENDING]),
        ];

        $this->travel(2)->hours();

        $newThread = $this->thread($spammer, 'Cheap watches');
        $newReply = $this->reply($this->thread($other, 'A new question'), $spammer);
        $newComment = $this->comment($spammer, BlogComment::STATUS_APPROVED);
        $newReports = [
            ForumThreadReport::create(['forum_thread_id' => $newThread->id, 'reporter_id' => $reporter->id, 'reason_category' => 'spam', 'reason' => 'Advert', 'status' => ForumThreadReport::STATUS_PENDING]),
            ForumPostReport::create(['forum_post_id' => $newReply->id, 'reporter_id' => $reporter->id, 'reason_category' => 'spam', 'reason' => 'Advert', 'status' => ForumPostReport::STATUS_PENDING]),
            BlogCommentReport::create(['blog_comment_id' => $newComment->id, 'reporter_id' => $reporter->id, 'reason_category' => 'spam', 'reason' => 'Advert', 'status' => BlogCommentReport::STATUS_PENDING]),
        ];

        $taken = $this->content()->takeDown($spammer, $moderator, 'Spam wave');

        $this->assertSame(3, $taken->reports);
        $this->assertSame(3, ModerationAction::query()->sole()->details['reports']);
        $this->assertTrue($this->reply_trashed($oldReply->id)->deleted_at->equalTo($deletedAt), 'a reply deleted earlier keeps the time it was deleted');

        foreach ($newReports as $report) {
            $report->refresh();
            $this->assertSame(ForumPostReport::STATUS_REVIEWED, $report->status);
            $this->assertSame($moderator->id, $report->reviewed_by);
            $this->assertNotNull($report->reviewed_at);
        }

        foreach ($oldReports as $report) {
            $report->refresh();
            $this->assertSame(ForumPostReport::STATUS_PENDING, $report->status, get_class($report));
            $this->assertNull($report->reviewed_by);
            $this->assertNull($report->reviewed_at);
        }
    }

    #[Test]
    public function it_is_written_to_the_history_and_the_member_is_not_told(): void
    {
        $wave = $this->spamWave();
        $spammer = $wave['spammer'];
        $moderator = $this->as('moderator');

        $this->content()->takeDown($spammer, $moderator, '  Spam wave on the lounge  ', 'Seen on three boards');

        $action = ModerationAction::query()->sole();
        $this->assertSame(ModerationAction::REMOVAL, $action->type);
        $this->assertSame($spammer->id, $action->user_id);
        $this->assertSame($moderator->id, $action->actor_id);
        $this->assertSame('Spam wave on the lounge', $action->reason);
        $this->assertSame('Seen on three boards', $action->note);
        $this->assertFalse($action->notified);
        $this->assertSame(1, $action->details['threads_hidden']);
        $this->assertSame(1, $action->details['threads_rejected']);
        $this->assertSame(2, $action->details['replies']);
        $this->assertSame(2, $action->details['comments']);
        $this->assertSame(2, $action->details['reviews']);
        $this->assertNull($action->expires_at);

        Notification::assertNothingSent();
        $this->assertFalse($spammer->fresh()->is_banned, 'taking their posts down is not a ban');

        $this->actingAs($spammer)->get(route('settings.standing'))->assertInertia(fn (Assert $page) => $page->has('notices', 0));

        $build = new \ReflectionMethod(GenerateUserDataExport::class, 'buildPayload');
        $data = $build->invoke(new GenerateUserDataExport(DataExport::factory()->for($spammer)->create()->id), $spammer);
        $this->assertSame([], $data['moderation']);
    }

    // --- Who may ---------------------------------------------------------------------------------------------------------------------

    #[Test]
    public function nobody_clears_out_their_own_account(): void
    {
        $moderator = $this->as('moderator');
        $thread = $this->thread($moderator, 'Notes');

        try {
            $this->content()->takeDown($moderator, $moderator, 'Cleaning up after myself');
            $this->fail('A refusal was expected.');
        } catch (ModerationRefused $refused) {
            $this->assertSame('You cannot moderate your own account.', $refused->getMessage());
        }

        $this->assertTrue($thread->fresh()->is_published);
    }

    #[Test]
    public function only_an_administrator_clears_out_a_member_of_staff(): void
    {
        $editor = $this->as('editor');
        $thread = $this->thread($editor, 'Notes');

        try {
            $this->content()->takeDown($editor, $this->as('moderator'), 'Not allowed');
            $this->fail('A refusal was expected.');
        } catch (ModerationRefused $refused) {
            $this->assertSame('Only an administrator can moderate a member of staff.', $refused->getMessage());
        }

        $this->assertTrue($thread->fresh()->is_published);
        $this->assertSame(0, ModerationAction::query()->count());

        $this->assertFalse($this->content()->takeDown($editor, $this->as('admin'), 'An administrator may')->isEmpty());
        $this->assertFalse($thread->fresh()->is_published);
    }

    #[Test]
    public function an_administrators_posts_can_come_down_though_they_cannot_be_banned(): void
    {
        $admin = $this->as('admin');
        $thread = $this->thread($admin, 'Notes');

        $this->assertNull($this->app->make(Moderation::class)->refusal($this->as('admin'), $admin, 'cleanup'));
        $this->content()->takeDown($admin, $this->as('admin'), 'Hide that please');

        $this->assertFalse($thread->fresh()->is_published);
    }

    // --- The moderation page and its routes -------------------------------------------------------------------------------------------

    #[Test]
    public function the_moderation_page_says_what_would_come_down_and_whether_the_moderator_may(): void
    {
        $wave = $this->spamWave();

        $this->actingAs($this->as('moderator'))
            ->get(route('acp.users.moderation.show', $wave['spammer']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('acp/UserModeration')
                ->where('member.showing.threads', 2)
                ->where('member.showing.replies', 2)
                ->where('member.showing.comments', 2)
                ->where('member.showing.reviews', 2)
                ->where('can.cleanup', true)
                ->where('refusal.cleanup', null));

        $viewer = $this->as('editor');
        $viewer->givePermissionTo('users.acp.view');
        $this->actingAs($viewer)
            ->get(route('acp.users.moderation.show', $wave['spammer']))
            ->assertInertia(fn (Assert $page) => $page->where('can.cleanup', false));

        $moderator = $this->as('moderator');
        $this->actingAs($moderator)
            ->get(route('acp.users.moderation.show', $moderator))
            ->assertInertia(fn (Assert $page) => $page->where('refusal.cleanup', 'You cannot moderate your own account.'));
    }

    #[Test]
    public function the_history_on_the_page_says_what_was_taken_down(): void
    {
        $wave = $this->spamWave();
        $moderator = $this->as('moderator');
        $this->content()->takeDown($wave['spammer'], $moderator, 'Spam wave');

        $this->actingAs($moderator)
            ->get(route('acp.users.moderation.show', $wave['spammer']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('history.0.type', 'removal')
                ->where('history.0.reason', 'Spam wave')
                ->where('history.0.summary', 'hid 1 thread, rejected 1 thread waiting for review, removed 2 replies, rejected 2 comments and rejected 2 reviews')
                ->where('history.0.notified', false));
    }

    #[Test]
    public function the_cleanup_route_needs_its_own_permission_and_a_reason(): void
    {
        $wave = $this->spamWave();
        $editor = $this->as('editor');
        $editor->givePermissionTo(['users.acp.view', 'users.acp.ban', 'users.acp.warn']);

        $this->actingAs($editor)->post(route('acp.users.cleanup', $wave['spammer']), ['reason' => 'Spam wave'])->assertForbidden();
        $this->assertTrue($wave['own']->fresh()->is_published);

        $moderator = $this->as('moderator');
        $this->actingAs($moderator)->post(route('acp.users.cleanup', $wave['spammer']), [])->assertSessionHasErrors('reason');
        $this->post(route('acp.users.cleanup', $wave['spammer']), ['reason' => 'no'])->assertSessionHasErrors('reason');
        $this->post(route('acp.users.cleanup', $wave['spammer']), ['reason' => str_repeat('a', 1001)])->assertSessionHasErrors('reason');
        $this->post(route('acp.users.cleanup', $wave['spammer']), ['reason' => 'Spam wave', 'note' => str_repeat('a', 1001)])->assertSessionHasErrors('note');
        $this->assertTrue($wave['own']->fresh()->is_published);

        $this->post(route('acp.users.cleanup', $wave['spammer']), ['reason' => 'Spam wave'])
            ->assertRedirect(route('acp.users.moderation.show', $wave['spammer']))
            ->assertSessionHas('success', 'Took down what '.$wave['spammer']->nickname.' posted: hid 1 thread, rejected 1 thread waiting for review, removed 2 replies, rejected 2 comments and rejected 2 reviews.');
        $this->assertFalse($wave['own']->fresh()->is_published);
    }

    #[Test]
    public function the_cleanup_route_says_so_when_there_was_nothing_to_take_down(): void
    {
        $member = $this->member();

        $this->actingAs($this->as('moderator'))
            ->post(route('acp.users.cleanup', $member), ['reason' => 'Spam wave'])
            ->assertRedirect(route('acp.users.moderation.show', $member))
            ->assertSessionHas('info', "Nothing of {$member->nickname}'s was showing, so nothing was taken down.");
    }

    #[Test]
    public function the_cleanup_route_gives_a_refusal_as_an_error_and_changes_nothing(): void
    {
        $editor = $this->as('editor');
        $thread = $this->thread($editor, 'Notes');

        $this->actingAs($this->as('moderator'))
            ->post(route('acp.users.cleanup', $editor), ['reason' => 'Not allowed'])
            ->assertSessionHas('error', 'Only an administrator can moderate a member of staff.');

        $this->assertTrue($thread->fresh()->is_published);
    }

    #[Test]
    public function a_ban_can_take_their_posts_down_in_the_same_go(): void
    {
        $wave = $this->spamWave();
        $moderator = $this->as('moderator');

        $this->actingAs($moderator)
            ->put(route('acp.users.ban', $wave['spammer']), ['reason' => 'Spam adverts', 'cleanup' => true, 'notify' => false])
            ->assertRedirect(route('acp.users.moderation.show', $wave['spammer']))
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'has been banned.') && str_contains($message, 'Their posts are down: hid 1 thread'));

        $this->assertTrue($wave['spammer']->fresh()->is_banned);
        $this->assertFalse($wave['own']->fresh()->is_published);
        $this->assertSame([ModerationAction::BAN, ModerationAction::REMOVAL], ModerationAction::query()->orderBy('id')->pluck('type')->all());
        $this->assertSame('Spam adverts', ModerationAction::query()->where('type', ModerationAction::REMOVAL)->sole()->reason);
    }

    #[Test]
    public function a_ban_alone_leaves_their_posts_up(): void
    {
        $wave = $this->spamWave();

        $this->actingAs($this->as('moderator'))
            ->put(route('acp.users.ban', $wave['spammer']), ['reason' => 'Spam adverts'])
            ->assertSessionHas('success', "{$wave['spammer']->nickname} has been banned.");

        $this->assertTrue($wave['spammer']->fresh()->is_banned);
        $this->assertTrue($wave['own']->fresh()->is_published);
        $this->assertSame([ModerationAction::BAN], ModerationAction::query()->pluck('type')->all());
    }

    #[Test]
    public function a_ban_that_says_nothing_of_theirs_was_showing_says_so(): void
    {
        $member = $this->member();

        $this->actingAs($this->as('moderator'))
            ->put(route('acp.users.ban', $member), ['reason' => 'Spam adverts', 'cleanup' => true])
            ->assertSessionHas('success', "{$member->nickname} has been banned. Nothing of theirs was showing.");

        $this->assertSame([ModerationAction::BAN], ModerationAction::query()->pluck('type')->all());
    }

    #[Test]
    public function a_moderator_who_may_ban_but_not_clear_out_is_refused_whole(): void
    {
        $wave = $this->spamWave();
        $editor = $this->as('editor');
        $editor->givePermissionTo(['users.acp.view', 'users.acp.ban']);

        $this->actingAs($editor)
            ->put(route('acp.users.ban', $wave['spammer']), ['reason' => 'Spam adverts', 'cleanup' => true])
            ->assertForbidden();

        $this->assertFalse($wave['spammer']->fresh()->is_banned, 'not banned either');
        $this->assertTrue($wave['own']->fresh()->is_published);

        $this->put(route('acp.users.ban', $wave['spammer']), ['reason' => 'Spam adverts'])->assertRedirect();
        $this->assertTrue($wave['spammer']->fresh()->is_banned, 'a ban without it is theirs to give');
    }

    #[Test]
    public function a_ban_that_is_refused_takes_nothing_down(): void
    {
        $admin = $this->as('admin');
        $thread = $this->thread($admin, 'Notes');

        $this->actingAs($this->as('admin'))
            ->put(route('acp.users.ban', $admin), ['reason' => 'Spam adverts', 'cleanup' => true])
            ->assertSessionHas('error', 'An administrator cannot be banned. Take the administrator role away first.');

        $this->assertTrue($thread->fresh()->is_published);
        $this->assertSame(0, ModerationAction::query()->count());
    }

    // --- Several at once --------------------------------------------------------------------------------------------------------------

    #[Test]
    public function several_members_are_banned_and_cleared_out_at_once_and_the_page_says_who_was_skipped(): void
    {
        $moderator = $this->as('moderator');
        $fresh = $this->spamWave();
        $alreadyBanned = $this->member();
        $alreadyBannedPost = $this->reply($this->thread($this->member(), 'Another question'), $alreadyBanned);
        $this->app->make(Moderation::class)->ban($alreadyBanned, $moderator, 'Banned earlier');
        $staff = $this->as('editor');
        $staffThread = $this->thread($staff, 'Notes');

        $this->actingAs($moderator)
            ->patch(route('acp.users.bulk-update'), [
                'action' => 'ban',
                'ids' => [$fresh['spammer']->id, $alreadyBanned->id, $staff->id],
                'reason' => 'Spam adverts',
                'cleanup' => true,
                'notify' => false,
            ])
            ->assertSessionHas('success', fn (string $message) => str_starts_with($message, 'Banned 1 user. What they posted is down: hid 1 thread, '))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'Not banned or cleared out: '.$staff->nickname));

        $this->assertTrue($fresh['spammer']->fresh()->is_banned);
        $this->assertFalse($fresh['own']->fresh()->is_published);
        $this->assertTrue($this->reply_trashed($alreadyBannedPost->id)->trashed(), 'somebody banned earlier is cleared out all the same');
        $this->assertSame(1, ModerationAction::query()->where('user_id', $alreadyBanned->id)->where('type', ModerationAction::BAN)->count(), 'and not banned again');
        $this->assertFalse($staff->fresh()->is_banned);
        $this->assertTrue($staffThread->fresh()->is_published, 'a member of staff is left alone, ban and clear-out');
    }

    #[Test]
    public function a_bulk_ban_that_is_refused_takes_nothing_down(): void
    {
        $admin = $this->as('admin');
        $thread = $this->thread($admin, 'Notes');

        $this->actingAs($this->as('admin'))
            ->patch(route('acp.users.bulk-update'), ['action' => 'ban', 'ids' => [$admin->id], 'reason' => 'Spam adverts', 'cleanup' => true])
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'An administrator cannot be banned.'));

        $this->assertFalse($admin->fresh()->is_banned);
        $this->assertTrue($thread->fresh()->is_published, 'an administrator whose ban was refused is not cleared out either');
        $this->assertSame(0, ModerationAction::query()->count());
    }

    #[Test]
    public function a_bulk_ban_without_the_clear_out_leaves_the_posts_up(): void
    {
        $wave = $this->spamWave();

        $this->actingAs($this->as('moderator'))
            ->patch(route('acp.users.bulk-update'), ['action' => 'ban', 'ids' => [$wave['spammer']->id], 'reason' => 'Spam adverts'])
            ->assertSessionHas('success', 'Banned 1 user.');

        $this->assertTrue($wave['own']->fresh()->is_published);
    }

    #[Test]
    public function several_members_are_cleared_out_without_a_ban(): void
    {
        $first = $this->spamWave();
        $second = $this->spamWave();
        $nothing = $this->member();

        $this->actingAs($this->as('moderator'))
            ->patch(route('acp.users.bulk-update'), [
                'action' => 'cleanup',
                'ids' => [$first['spammer']->id, $second['spammer']->id, $nothing->id],
                'reason' => 'Spam wave',
            ])
            ->assertSessionHas('success', 'Took down what 2 users posted: hid 2 threads, rejected 2 threads waiting for review, removed 4 replies, rejected 4 comments and rejected 4 reviews.');

        $this->assertFalse($first['spammer']->fresh()->is_banned);
        $this->assertFalse($first['own']->fresh()->is_published);
        $this->assertFalse($second['own']->fresh()->is_published);
        $this->assertSame(2, ModerationAction::query()->where('type', ModerationAction::REMOVAL)->count());
    }

    #[Test]
    public function a_bulk_clear_out_of_members_with_nothing_showing_says_so(): void
    {
        $this->actingAs($this->as('moderator'))
            ->patch(route('acp.users.bulk-update'), ['action' => 'cleanup', 'ids' => [$this->member()->id], 'reason' => 'Spam wave'])
            ->assertSessionHas('success', 'Nothing of theirs was showing, so nothing was taken down.');
    }

    #[Test]
    public function a_bulk_clear_out_names_the_members_it_may_not_touch(): void
    {
        $staff = $this->as('editor');
        $thread = $this->thread($staff, 'Notes');

        $this->actingAs($this->as('moderator'))
            ->patch(route('acp.users.bulk-update'), ['action' => 'cleanup', 'ids' => [$staff->id], 'reason' => 'Spam wave'])
            ->assertSessionHas('error', 'Not cleared out: '.$staff->nickname.' (Only an administrator can moderate a member of staff.).');

        $this->assertTrue($thread->fresh()->is_published);
    }

    #[Test]
    public function a_bulk_clear_out_needs_a_reason_and_its_own_permission(): void
    {
        $wave = $this->spamWave();
        $moderator = $this->as('moderator');

        $this->actingAs($moderator)
            ->patch(route('acp.users.bulk-update'), ['action' => 'cleanup', 'ids' => [$wave['spammer']->id]])
            ->assertSessionHasErrors('reason');
        $this->patch(route('acp.users.bulk-update'), ['action' => 'ban', 'ids' => [$wave['spammer']->id], 'cleanup' => true])
            ->assertSessionHasErrors('reason');

        $editor = $this->as('editor');
        $editor->givePermissionTo(['users.acp.view', 'users.acp.ban']);

        $this->actingAs($editor)
            ->patch(route('acp.users.bulk-update'), ['action' => 'cleanup', 'ids' => [$wave['spammer']->id], 'reason' => 'Spam wave'])
            ->assertForbidden();
        $this->patch(route('acp.users.bulk-update'), ['action' => 'ban', 'ids' => [$wave['spammer']->id], 'reason' => 'Spam adverts', 'cleanup' => true])
            ->assertForbidden();

        $this->assertFalse($wave['spammer']->fresh()->is_banned, 'nobody was banned by the refused one');
        $this->assertTrue($wave['own']->fresh()->is_published);
    }

    // --- Where it is recorded and who has it ------------------------------------------------------------------------------------------

    #[Test]
    public function the_cleanup_is_in_the_audit_log(): void
    {
        $wave = $this->spamWave();

        $this->actingAs($this->as('admin'))->post(route('acp.users.cleanup', $wave['spammer']), ['reason' => 'Spam wave'])->assertRedirect();

        $entry = AuditLog::query()->latest('id')->first();
        $this->assertSame('users.cleanup', $entry->event);
        $this->assertSame(AuditLog::SUCCEEDED, $entry->outcome);
        $this->assertSame($wave['spammer']->id, $entry->subject_id);
        $this->assertSame('Took down what a user posted', config('audit.events')['users.cleanup']);
    }

    #[Test]
    public function the_seeded_moderator_may_clear_out_and_the_editor_may_not(): void
    {
        $this->assertTrue($this->as('moderator')->can('users.acp.cleanup'));
        $this->assertFalse($this->as('editor')->can('users.acp.cleanup'));
        $this->assertTrue($this->as('admin')->can('users.acp.cleanup'));
    }
}
