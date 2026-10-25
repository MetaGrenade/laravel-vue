<?php

namespace Tests\Feature\Admin;

use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The queue of what automatic moderation has held (docs/automod.md): who may see it, what it shows, approving, rejecting, and doing
 * several at once.
 */
class ForumReviewTest extends TestCase
{
    use RefreshDatabase;

    private ForumBoard $board;

    private ForumThread $thread;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Notification::fake();

        $category = ForumCategory::create(['title' => 'General', 'slug' => 'general', 'description' => 'Chat', 'position' => 1]);
        $this->board = ForumBoard::create(['forum_category_id' => $category->id, 'title' => 'The lounge', 'slug' => 'the-lounge', 'description' => 'Chat', 'position' => 1]);
        $this->thread = $this->makeThread($this->member(), 'Welcome');
    }

    private function member(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }

    private function as(string $role): User
    {
        $user = $this->member();
        $user->assignRole($role);

        return $user;
    }

    private function makeThread(User $author, string $title, array $attributes = []): ForumThread
    {
        $thread = new ForumThread([
            'forum_board_id' => $this->board->id,
            'user_id' => $author->id,
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::random(4),
            'excerpt' => $title,
            'is_locked' => false,
            'is_pinned' => false,
            'is_published' => true,
            'views' => 0,
        ]);
        $thread->forceFill($attributes)->save();

        return $thread;
    }

    private function heldReply(User $author, string $body = '<p>Buy cheap watches</p>', string $reason = 'Watch spam: contains "cheap watches"'): ForumPost
    {
        $post = new ForumPost(['forum_thread_id' => $this->thread->id, 'user_id' => $author->id, 'body' => $body]);
        $post->forceFill(['held_at' => now(), 'held_reason' => $reason])->save();

        return $post;
    }

    private function heldThread(User $author, string $title = 'Free money here'): ForumThread
    {
        $thread = $this->makeThread($author, $title, ['is_published' => false, 'held_at' => now(), 'held_reason' => 'Money talk: contains "free money"']);
        ForumPost::create(['forum_thread_id' => $thread->id, 'user_id' => $author->id, 'body' => '<p>Come and see the offer</p>']);

        return $thread;
    }

    // --- Who ------------------------------------------------------------------------------------------------------------------

    #[Test]
    public function the_queue_is_for_signed_in_staff_who_may_review(): void
    {
        $this->get(route('acp.forums.review.index'))->assertRedirect(route('login'));
        $this->actingAs($this->member())->get(route('acp.forums.review.index'))->assertForbidden();
        $this->actingAs($this->as('editor'))->get(route('acp.forums.review.index'))->assertForbidden();

        $this->actingAs($this->as('moderator'))->get(route('acp.forums.review.index'))->assertOk();
        $this->actingAs($this->as('admin'))->get(route('acp.forums.review.index'))->assertOk();
    }

    #[Test]
    public function every_action_needs_the_same_permission(): void
    {
        $reply = $this->heldReply($this->member());
        $thread = $this->heldThread($this->member());
        $editor = $this->as('editor');

        $this->actingAs($editor)->post(route('acp.forums.review.replies.approve', $reply->id))->assertForbidden();
        $this->delete(route('acp.forums.review.replies.reject', $reply->id))->assertForbidden();
        $this->post(route('acp.forums.review.threads.approve', $thread->id))->assertForbidden();
        $this->delete(route('acp.forums.review.threads.reject', $thread->id))->assertForbidden();
        $this->post(route('acp.forums.review.bulk'), ['action' => 'approve', 'replies' => [$reply->id]])->assertForbidden();

        $this->assertNotNull(ForumPost::query()->withoutGlobalScope(ForumPost::VISIBLE)->find($reply->id)->held_at);
        $this->assertNotNull($thread->fresh()->held_at);
    }

    // --- What it shows --------------------------------------------------------------------------------------------------------

    #[Test]
    public function the_queue_lists_held_replies_oldest_first_with_who_wrote_them(): void
    {
        $newcomer = $this->member(['nickname' => 'Newcomer', 'created_at' => now()->subDays(2)]);
        $regular = $this->member(['nickname' => 'Regular', 'created_at' => now()->subYear()]);
        ForumPost::create(['forum_thread_id' => $this->thread->id, 'user_id' => $regular->id, 'body' => '<p>An earlier post that is fine</p>']);

        $second = $this->heldReply($regular, '<p>Second <b>held</b> reply</p>');
        $second->forceFill(['held_at' => now()])->save();
        $first = $this->heldReply($newcomer, '<p>First held reply</p>');
        $first->forceFill(['held_at' => now()->subHour()])->save();

        $this->actingAs($this->as('moderator'))->get(route('acp.forums.review.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('acp/ForumReview')
                ->where('type', 'replies')
                ->where('counts', ['replies' => 2, 'threads' => 0])
                ->has('items.data', 2)
                ->where('items.data.0.id', $first->id)
                ->where('items.data.0.excerpt', 'First held reply')
                ->where('items.data.0.author.nickname', 'Newcomer')
                ->where('items.data.0.author.approved_posts', 0)
                ->where('items.data.0.reason', 'Watch spam: contains "cheap watches"')
                ->where('items.data.0.title', 'Welcome')
                ->where('items.data.1.id', $second->id)
                ->where('items.data.1.excerpt', 'Second held reply')
                ->where('items.data.1.author.approved_posts', 1, 'the one fine post, and not the held one'));
    }

    #[Test]
    public function the_threads_tab_lists_held_threads_with_what_they_start_with(): void
    {
        $author = $this->member(['nickname' => 'Poster']);
        $thread = $this->heldThread($author);
        $this->heldReply($this->member());

        $this->actingAs($this->as('moderator'))->get(route('acp.forums.review.index', ['type' => 'threads']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('type', 'threads')
                ->where('counts', ['replies' => 1, 'threads' => 1])
                ->has('items.data', 1)
                ->where('items.data.0.id', $thread->id)
                ->where('items.data.0.kind', 'thread')
                ->where('items.data.0.title', 'Free money here')
                ->where('items.data.0.excerpt', 'Come and see the offer')
                ->where('items.data.0.reason', 'Money talk: contains "free money"')
                ->where('items.data.0.author.nickname', 'Poster'));
    }

    #[Test]
    public function an_empty_queue_is_empty(): void
    {
        $this->actingAs($this->as('moderator'))->get(route('acp.forums.review.index'))
            ->assertInertia(fn (Assert $page) => $page->where('counts', ['replies' => 0, 'threads' => 0])->has('items.data', 0));
    }

    #[Test]
    public function the_queue_is_paged(): void
    {
        $author = $this->member();

        foreach (range(1, 30) as $n) {
            $this->heldReply($author, "<p>Held {$n}</p>");
        }

        $this->actingAs($this->as('moderator'))->get(route('acp.forums.review.index'))
            ->assertInertia(fn (Assert $page) => $page->has('items.data', 25)->where('items.meta.total', 30));
    }

    #[Test]
    public function a_link_to_the_authors_moderation_page_is_offered_to_somebody_who_may_use_it(): void
    {
        $author = $this->member();
        $reply = $this->heldReply($author);
        $moderator = $this->as('moderator');

        $this->actingAs($moderator)->get(route('acp.forums.review.index'))
            ->assertInertia(fn (Assert $page) => $page->where('items.data.0.moderate_url', route('acp.users.moderation.show', ['user' => $author->id, 'subject' => 'forum_post:'.$reply->id])));

        // A reviewer who may neither warn nor ban is not offered it, and nor is anybody for their own post.
        $reviewer = $this->as('editor');
        $reviewer->givePermissionTo('forums.acp.review');
        $this->actingAs($reviewer)->get(route('acp.forums.review.index'))
            ->assertInertia(fn (Assert $page) => $page->where('items.data.0.moderate_url', null));

        $own = $this->heldReply($moderator);
        $this->actingAs($moderator)->get(route('acp.forums.review.index'))
            ->assertInertia(fn (Assert $page) => $page->where('items.data', fn ($items) => collect($items)->firstWhere('id', $own->id)['moderate_url'] === null));
    }

    #[Test]
    public function the_moderation_page_names_a_held_reply_a_moderator_came_from(): void
    {
        $author = $this->member();
        $reply = $this->heldReply($author);

        $this->actingAs($this->as('moderator'))->get(route('acp.users.moderation.show', ['user' => $author->id, 'subject' => 'forum_post:'.$reply->id]))
            ->assertInertia(fn (Assert $page) => $page->where('subject.label', 'Reply in "Welcome"'));
    }

    // --- Approving and rejecting ----------------------------------------------------------------------------------------------

    #[Test]
    public function approving_a_reply_puts_it_in_the_thread(): void
    {
        $author = $this->member();
        $reply = $this->heldReply($author);

        $this->actingAs($this->as('moderator'))->post(route('acp.forums.review.replies.approve', $reply->id))
            ->assertRedirect()
            ->assertSessionHas('success', 'The reply is approved and is now in the thread.');

        $this->assertSame(1, $this->thread->posts()->count());
        $this->assertSame(5, $author->fresh()->reputation_points);
    }

    #[Test]
    public function approving_one_somebody_else_has_dealt_with_says_so(): void
    {
        $reply = $this->heldReply($this->member());
        $moderator = $this->as('moderator');

        $this->actingAs($moderator)->post(route('acp.forums.review.replies.approve', $reply->id));
        $this->post(route('acp.forums.review.replies.approve', $reply->id))
            ->assertSessionHas('info', 'Somebody else has already dealt with that one.')
            ->assertSessionMissing('success');
    }

    #[Test]
    public function rejecting_a_reply_removes_it(): void
    {
        $reply = $this->heldReply($this->member());

        $this->actingAs($this->as('moderator'))->delete(route('acp.forums.review.replies.reject', $reply->id))
            ->assertRedirect()
            ->assertSessionHas('success', 'The reply is rejected and has been removed.');

        $this->assertSame(0, ForumPost::query()->withoutGlobalScope(ForumPost::VISIBLE)->count());
    }

    #[Test]
    public function a_reply_that_is_not_held_is_not_the_queues_to_reject(): void
    {
        $fine = ForumPost::create(['forum_thread_id' => $this->thread->id, 'user_id' => $this->member()->id, 'body' => '<p>A fine reply</p>']);

        $this->actingAs($this->as('moderator'))->delete(route('acp.forums.review.replies.reject', $fine->id))
            ->assertSessionHas('info');

        $this->assertModelExists($fine);
    }

    #[Test]
    public function a_reply_that_is_not_there_is_not_found(): void
    {
        $moderator = $this->as('moderator');

        $this->actingAs($moderator)->post(route('acp.forums.review.replies.approve', 999999))->assertNotFound();
        $this->delete(route('acp.forums.review.replies.reject', 999999))->assertNotFound();
        $this->post(route('acp.forums.review.threads.approve', 999999))->assertNotFound();
    }

    #[Test]
    public function approving_and_rejecting_a_thread(): void
    {
        $author = $this->member();
        $approved = $this->heldThread($author, 'To approve');
        $rejected = $this->heldThread($author, 'To reject');
        $moderator = $this->as('moderator');

        $this->actingAs($moderator)->post(route('acp.forums.review.threads.approve', $approved->id))
            ->assertSessionHas('success', 'The thread is approved and is now published.');
        $this->delete(route('acp.forums.review.threads.reject', $rejected->id))
            ->assertSessionHas('success', 'The thread is rejected and has been removed.');

        $this->assertTrue($approved->fresh()->is_published);
        $this->assertNull($approved->fresh()->held_at);
        $this->assertModelMissing($rejected);
        $this->assertSame(5, $author->fresh()->reputation_points);
    }

    #[Test]
    public function an_approved_thread_goes_to_the_top_of_its_board_as_a_new_one_would(): void
    {
        $thread = $this->heldThread($this->member());
        $thread->forceFill(['last_posted_at' => now()->subWeek()])->save();

        $this->actingAs($this->as('moderator'))->post(route('acp.forums.review.threads.approve', $thread->id));

        $this->assertTrue($thread->fresh()->last_posted_at->greaterThan(now()->subMinute()));
    }

    #[Test]
    public function a_thread_that_is_not_held_is_not_the_queues_to_reject(): void
    {
        $fine = $this->makeThread($this->member(), 'A fine thread');

        $this->actingAs($this->as('moderator'))->delete(route('acp.forums.review.threads.reject', $fine->id))
            ->assertSessionHas('info');

        $this->assertModelExists($fine);
    }

    // --- Several at once ------------------------------------------------------------------------------------------------------

    #[Test]
    public function several_can_be_approved_at_once_and_what_is_not_held_is_left_alone(): void
    {
        $author = $this->member();
        $one = $this->heldReply($author);
        $two = $this->heldReply($author);
        $thread = $this->heldThread($author);
        $fine = ForumPost::create(['forum_thread_id' => $this->thread->id, 'user_id' => $author->id, 'body' => '<p>A fine reply</p>']);

        $this->actingAs($this->as('moderator'))
            ->post(route('acp.forums.review.bulk'), ['action' => 'approve', 'replies' => [$one->id, $two->id, $fine->id, 999999], 'threads' => [$thread->id, 999999]])
            ->assertSessionHas('success', '3 items approved.');

        $this->assertSame(3, $this->thread->posts()->count());
        $this->assertTrue($thread->fresh()->is_published);
    }

    #[Test]
    public function several_can_be_rejected_at_once(): void
    {
        $author = $this->member();
        $one = $this->heldReply($author);
        $thread = $this->heldThread($author);

        $this->actingAs($this->as('moderator'))
            ->post(route('acp.forums.review.bulk'), ['action' => 'reject', 'replies' => [$one->id], 'threads' => [$thread->id]])
            ->assertSessionHas('success', '2 items rejected.');

        $this->assertSame(0, ForumPost::query()->withoutGlobalScope(ForumPost::VISIBLE)->whereNotNull('held_at')->count());
        $this->assertSame(0, ForumThread::query()->whereNotNull('held_at')->count());
    }

    #[Test]
    public function a_bulk_action_with_nothing_to_do_says_so_and_a_bad_one_is_refused(): void
    {
        $moderator = $this->as('moderator');

        $this->actingAs($moderator)->post(route('acp.forums.review.bulk'), ['action' => 'approve', 'replies' => [999999]])
            ->assertSessionHas('success', 'Nothing was left to approve.');

        $this->post(route('acp.forums.review.bulk'), ['action' => 'delete-everything'])->assertSessionHasErrors('action');
        $this->post(route('acp.forums.review.bulk'), ['action' => 'approve', 'replies' => range(1, 201)])->assertSessionHasErrors('replies');
    }

    #[Test]
    public function the_queue_is_in_the_sidebar_for_those_who_may_review_and_the_rules_for_those_who_may_edit_them(): void
    {
        // The links are drawn by the page from the permissions it is given; what must hold is that the pages each answer to their own.
        $moderator = $this->as('moderator');

        $this->actingAs($moderator)->get(route('acp.forums.review.index'))->assertOk();
        $this->actingAs($moderator)->get(route('acp.forums.automod.index'))->assertForbidden();
    }

    #[Test]
    public function a_moderator_is_given_the_forum_pages_a_moderators_work_needs(): void
    {
        $moderator = $this->as('moderator');

        $this->actingAs($moderator)->get(route('acp.forums.index'))->assertOk();
        $this->assertTrue($moderator->can('forums.acp.review'));
        $this->assertFalse($moderator->can('forums.acp.automod'), 'the rules are an administrator\'s');
    }
}
