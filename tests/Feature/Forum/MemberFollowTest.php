<?php

namespace Tests\Feature\Forum;

use App\Models\AutomodRule;
use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\MemberFollow;
use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\ForumFollowedMemberPosted;
use App\Notifications\ForumPostMentioned;
use App\Notifications\ForumThreadUpdated;
use App\Support\WebsiteSections;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A member following another member (docs/member-follows.md).
 */
class MemberFollowTest extends TestCase
{
    use RefreshDatabase;

    private ForumBoard $board;

    private ForumThread $thread;

    private User $author;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $category = ForumCategory::create([
            'title' => 'General',
            'slug' => 'general',
            'description' => 'Chat',
            'position' => 1,
        ]);

        $this->board = ForumBoard::create([
            'forum_category_id' => $category->id,
            'title' => 'Announcements',
            'slug' => 'announcements',
            'description' => 'News',
            'position' => 1,
        ]);

        $this->author = User::factory()->create();

        $this->thread = ForumThread::create([
            'forum_board_id' => $this->board->id,
            'user_id' => $this->author->id,
            'title' => 'Thread title',
            'slug' => 'thread-title',
            'excerpt' => null,
            'is_locked' => false,
            'is_pinned' => false,
            'is_published' => true,
            'views' => 0,
            'last_posted_at' => now(),
            'last_post_user_id' => $this->author->id,
        ]);

        ForumPost::create([
            'forum_thread_id' => $this->thread->id,
            'user_id' => $this->author->id,
            'body' => '<p>Opening post</p>',
        ]);
    }

    public function test_a_member_can_follow_someone_and_then_stop(): void
    {
        $viewer = User::factory()->create();
        $moderator = User::factory()->create();
        $moderator->assignRole('moderator');

        $this->actingAs($viewer)
            ->get(route('members.show', $this->author))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('follow.can', true)
                ->where('follow.following', false));

        $this->actingAs($viewer)
            ->get(route('members.show', $moderator))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('follow.can', true)
                ->where('ignore.can', false));

        $this->actingAs($viewer)->put(route('forum.follows.store', $this->author))->assertRedirect();
        $this->actingAs($viewer)->put(route('forum.follows.store', $this->author))->assertRedirect();
        $this->assertSame(1, MemberFollow::query()->count());

        $this->actingAs($viewer)
            ->get(route('members.show', $this->author))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('follow.following', true));

        $this->actingAs($viewer)
            ->get(route('privacy.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('following.0.nickname', $this->author->nickname));

        $this->actingAs($viewer)
            ->get(route('members.show', $viewer))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('follow.can', false)
                ->where('follow.following', false));

        $this->actingAs($viewer)->delete(route('forum.follows.destroy', $this->author))->assertRedirect();
        $this->assertSame(0, MemberFollow::query()->count());
    }

    public function test_the_feed_shows_public_posts_and_leaves_out_what_is_hidden(): void
    {
        $viewer = User::factory()->create();
        $stranger = User::factory()->create();

        $this->actingAs($viewer)->put(route('forum.follows.store', $this->author))->assertRedirect();

        ForumPost::create([
            'forum_thread_id' => $this->thread->id,
            'user_id' => $this->author->id,
            'body' => '<p>A public reply</p>',
        ]);

        ForumPost::create([
            'forum_thread_id' => $this->thread->id,
            'user_id' => $stranger->id,
            'body' => '<p>Someone else</p>',
        ]);

        $held = ForumPost::create([
            'forum_thread_id' => $this->thread->id,
            'user_id' => $this->author->id,
            'body' => '<p>Waiting</p>',
        ]);
        $held->forceFill(['held_at' => now()])->save();

        $hiddenThread = ForumThread::create([
            'forum_board_id' => $this->board->id,
            'user_id' => $this->author->id,
            'title' => 'Not published',
            'slug' => 'not-published',
            'excerpt' => null,
            'is_locked' => false,
            'is_pinned' => false,
            'is_published' => false,
            'views' => 0,
            'last_posted_at' => now(),
            'last_post_user_id' => $this->author->id,
        ]);

        ForumPost::create([
            'forum_thread_id' => $hiddenThread->id,
            'user_id' => $this->author->id,
            'body' => '<p>Hidden thread</p>',
        ]);

        $this->actingAs($viewer)
            ->get(route('forum.following'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('people.0.nickname', $this->author->nickname)
                ->has('posts.data', 2)
                ->where('posts.data.0.excerpt', 'A public reply')
                ->where('posts.data.0.thread', 'Thread title')
                ->where('posts.data.0.url', fn (string $url): bool => str_contains($url, '#post-')));

        $this->actingAs($viewer)->put(route('forum.ignores.store', $this->author))->assertRedirect();

        $this->actingAs($viewer)
            ->get(route('forum.following'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('posts.data', 0)
                ->where('people.0.nickname', $this->author->nickname));

        $this->actingAs($viewer)->delete(route('forum.ignores.destroy', $this->author))->assertRedirect();

        $this->author->forceFill(['is_banned' => true])->save();

        $this->actingAs($viewer)
            ->get(route('forum.following'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('posts.data', 0)
                ->where('people.0.banned', true));
    }

    public function test_a_follower_is_told_when_they_post_unless_they_already_heard_or_ignore_them(): void
    {
        Notification::fake();

        $follower = User::factory()->create();
        $subscriber = User::factory()->create();
        $ignorer = User::factory()->create();
        $mentioned = User::factory()->create(['nickname' => 'Heard']);

        $this->thread->subscribers()->attach($subscriber);

        foreach ([$follower, $subscriber, $ignorer, $mentioned] as $member) {
            $this->actingAs($member)->put(route('forum.follows.store', $this->author))->assertRedirect();
        }

        $this->actingAs($ignorer)->put(route('forum.ignores.store', $this->author))->assertRedirect();

        $this->actingAs($this->author)->post(route('forum.posts.store', [$this->board, $this->thread]), [
            'body' => '<p>Hello @Heard</p>',
        ])->assertRedirect();

        Notification::assertSentTo($follower, ForumFollowedMemberPosted::class);
        Notification::assertNotSentTo($subscriber, ForumFollowedMemberPosted::class);
        Notification::assertSentTo($subscriber, ForumThreadUpdated::class);
        Notification::assertNotSentTo($ignorer, ForumFollowedMemberPosted::class);
        Notification::assertNotSentTo($ignorer, ForumThreadUpdated::class);
        Notification::assertSentTo($mentioned, ForumPostMentioned::class);
        Notification::assertNotSentTo($mentioned, ForumFollowedMemberPosted::class);

        $told = Notification::sent($follower, ForumFollowedMemberPosted::class)->count();
        $this->assertGreaterThan(0, $told);

        AutomodRule::factory()->words(['cheap watches'])->create();

        $this->actingAs($this->author)->post(route('forum.posts.store', [$this->board, $this->thread]), [
            'body' => '<p>cheap watches</p>',
        ])->assertRedirect();

        $this->assertCount($told, Notification::sent($follower, ForumFollowedMemberPosted::class), 'a held reply tells nobody');

        $this->actingAs($this->author)->post(route('forum.threads.store', $this->board), [
            'title' => 'A new thread',
            'body' => '<p>Started</p>',
        ])->assertRedirect();

        $this->assertGreaterThan($told, Notification::sent($follower, ForumFollowedMemberPosted::class)->count());
        Notification::assertSentTo($mentioned, ForumFollowedMemberPosted::class);
        Notification::assertNotSentTo($ignorer, ForumFollowedMemberPosted::class);
    }

    public function test_a_notice_opens_the_page_the_reply_is_on(): void
    {
        Notification::fake();

        $follower = User::factory()->create();
        $this->actingAs($follower)->put(route('forum.follows.store', $this->author))->assertRedirect();

        $this->actingAs($this->author)->post(route('forum.posts.store', [$this->board, $this->thread]), [
            'body' => '<p>Early</p>',
        ])->assertRedirect();

        $early = ForumPost::query()->where('body', '<p>Early</p>')->firstOrFail();
        $earlyUrl = route('forum.threads.show', [$this->board, $this->thread]).'#post-'.$early->id;
        $earlyNotice = Notification::sent($follower, ForumFollowedMemberPosted::class)->first();
        $this->assertInstanceOf(ForumFollowedMemberPosted::class, $earlyNotice);
        $this->assertSame($earlyUrl, $earlyNotice->toArray($follower)['url']);
        $this->assertSame($earlyUrl, $earlyNotice->toMail($follower)->actionUrl);

        $held = ForumPost::create([
            'forum_thread_id' => $this->thread->id,
            'user_id' => $this->author->id,
            'body' => '<p>Held</p>',
        ]);
        $held->forceFill(['held_at' => now()])->save();

        $removed = ForumPost::create([
            'forum_thread_id' => $this->thread->id,
            'user_id' => $this->author->id,
            'body' => '<p>Removed</p>',
        ]);
        $removed->delete();

        // The opening post and the early reply are already on the first page. Eight more fill it.
        for ($number = 0; $number < 8; $number++) {
            ForumPost::create([
                'forum_thread_id' => $this->thread->id,
                'user_id' => $this->author->id,
                'body' => '<p>Filler '.$number.'</p>',
            ]);
        }

        $this->actingAs($this->author)->post(route('forum.posts.store', [$this->board, $this->thread]), [
            'body' => '<p>Later</p>',
        ])->assertRedirect();

        $later = ForumPost::query()->where('body', '<p>Later</p>')->firstOrFail();
        $laterUrl = route('forum.threads.show', [
            'board' => $this->board,
            'thread' => $this->thread,
            'page' => 2,
        ]).'#post-'.$later->id;

        $laterNotice = Notification::sent($follower, ForumFollowedMemberPosted::class)->last();
        $this->assertInstanceOf(ForumFollowedMemberPosted::class, $laterNotice);
        $this->assertNotSame($earlyNotice, $laterNotice);
        $this->assertSame($laterUrl, $laterNotice->toArray($follower)['url']);
        $this->assertSame($laterUrl, $laterNotice->toMail($follower)->actionUrl);

        $pageOne = collect($this->actingAs($follower)
            ->get(route('forum.threads.show', [$this->board, $this->thread]))
            ->viewData('page')['props']['posts']['data'])->pluck('id');
        $pageTwo = collect($this->actingAs($follower)
            ->get(route('forum.threads.show', [$this->board, $this->thread, 'page' => 2]))
            ->viewData('page')['props']['posts']['data'])->pluck('id');

        $this->assertNotContains($later->id, $pageOne->all());
        $this->assertContains($later->id, $pageTwo->all());

        $this->actingAs($follower)
            ->get(route('forum.following'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('posts.data.0.url', $laterUrl));
    }

    public function test_you_cannot_follow_yourself_or_a_banned_member_and_the_forum_can_be_off(): void
    {
        $viewer = User::factory()->create();
        $banned = User::factory()->create(['is_banned' => true]);
        $other = User::factory()->create();

        $this->actingAs($viewer)
            ->from(route('members.show', $viewer))
            ->put(route('forum.follows.store', $viewer))
            ->assertSessionHasErrors('user');

        $this->actingAs($viewer)->put(route('forum.follows.store', $banned))->assertNotFound();

        auth()->logout();

        $this->get(route('members.show', $this->author))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('follow', null));

        $this->get(route('forum.following'))->assertRedirect(route('login'));

        $this->actingAs($viewer)->put(route('forum.follows.store', $other))->assertRedirect();
        $other->delete();
        $this->assertDatabaseMissing('member_follows', ['followed_user_id' => $other->id]);

        $sections = WebsiteSections::defaults();
        $sections[WebsiteSections::FORUM] = false;
        SystemSetting::set('website_sections', $sections);

        $this->actingAs($viewer)
            ->get(route('members.show', $this->author))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('follow', null)
                ->where('ignore', null));

        $this->actingAs($viewer)
            ->get(route('privacy.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('following', null)
                ->where('ignored', null));

        $this->actingAs($viewer)->get(route('forum.following'))->assertNotFound();
    }
}
