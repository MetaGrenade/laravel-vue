<?php

namespace Tests\Feature\Forum;

use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\ForumPostMentioned;
use App\Notifications\ForumThreadUpdated;
use App\Support\WebsiteSections;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A member choosing not to see another member in the forum (docs/forum-ignores.md).
 */
class ForumIgnoreTest extends TestCase
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

    public function test_a_member_can_ignore_someone_and_then_stop(): void
    {
        $viewer = User::factory()->create();
        $member = User::factory()->create(['nickname' => 'Ignored Member']);
        $this->reply($member, '<p>A reply</p>');

        $this->actingAs($viewer)
            ->put(route('forum.ignores.store', $member))
            ->assertRedirect()
            ->assertSessionHas('success', 'You are ignoring Ignored Member.');

        $this->actingAs($viewer)
            ->put(route('forum.ignores.store', $member))
            ->assertRedirect();

        $this->assertDatabaseCount('forum_ignores', 1);

        $this->actingAs($viewer)
            ->get(route('forum.threads.show', [$this->board, $this->thread]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ForumThreadView')
                ->where('posts.data.0.ignored', false)
                ->where('posts.data.0.permissions.canIgnore', true)
                ->where('posts.data.1.ignored', true)
                ->where('posts.data.1.permissions.canIgnore', true)
                ->where('ignoredUserIds', [$member->id]));

        $this->actingAs($viewer)
            ->get(route('privacy.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('ignored.0.id', $member->id)
                ->where('ignored.0.nickname', 'Ignored Member')
                ->where('ignored.0.banned', false));

        $this->actingAs($viewer)
            ->get(route('members.show', $member))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('ignore.can', true)
                ->where('ignore.ignoring', true));

        $member->forceFill(['is_banned' => true])->save();

        $this->actingAs($viewer)
            ->get(route('privacy.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('ignored.0.banned', true));

        auth()->logout();

        $this->get(route('forum.threads.show', [$this->board, $this->thread]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('posts.data.1.ignored', false)
                ->where('posts.data.1.permissions.canIgnore', false)
                ->where('ignoredUserIds', []));

        $this->actingAs($viewer)
            ->delete(route('forum.ignores.destroy', $member))
            ->assertRedirect()
            ->assertSessionHas('success', 'You stopped ignoring Ignored Member.');

        $this->assertDatabaseCount('forum_ignores', 0);

        $this->actingAs($viewer)
            ->get(route('forum.threads.show', [$this->board, $this->thread]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('posts.data.1.ignored', false));

        $this->actingAs($viewer)
            ->get(route('privacy.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('ignored', []));
    }

    public function test_you_cannot_ignore_yourself(): void
    {
        $this->actingAs($this->author)
            ->put(route('forum.ignores.store', $this->author))
            ->assertInvalid(['user' => 'You cannot ignore yourself.']);

        $this->assertDatabaseCount('forum_ignores', 0);

        $this->actingAs($this->author)
            ->get(route('members.show', $this->author))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('ignore.can', false)
                ->where('ignore.ignoring', false));
    }

    public function test_you_cannot_ignore_a_moderator(): void
    {
        $viewer = User::factory()->create();
        $moderator = User::factory()->create();
        $moderator->assignRole('moderator');
        $this->reply($moderator, '<p>Official word</p>');

        $this->actingAs($viewer)
            ->put(route('forum.ignores.store', $moderator))
            ->assertInvalid(['user' => 'Moderators cannot be ignored.']);

        $this->assertDatabaseCount('forum_ignores', 0);

        $this->actingAs($viewer)
            ->get(route('forum.threads.show', [$this->board, $this->thread]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('posts.data.1.permissions.canIgnore', false)
                ->where('posts.data.1.ignored', false));
    }

    public function test_someone_who_ignores_the_author_is_not_told_about_a_reply_or_a_mention(): void
    {
        Notification::fake();

        $replier = User::factory()->create();
        $follower = User::factory()->create();
        $ignorer = User::factory()->create();
        $mentioned = User::factory()->create(['nickname' => 'Heard']);
        $quiet = User::factory()->create(['nickname' => 'Quiet']);

        $this->thread->subscribers()->attach([$follower->id, $ignorer->id]);

        $this->actingAs($ignorer)->put(route('forum.ignores.store', $replier))->assertRedirect();
        $this->actingAs($quiet)->put(route('forum.ignores.store', $replier))->assertRedirect();

        $this->actingAs($replier)->post(route('forum.posts.store', [$this->board, $this->thread]), [
            'body' => '<p>Hello @Heard and @Quiet</p>',
        ])->assertRedirect();

        $reply = ForumPost::query()->where('forum_thread_id', $this->thread->id)->latest('id')->first();
        $this->assertNotNull($reply);

        Notification::assertSentTo($follower, ForumThreadUpdated::class);
        Notification::assertNotSentTo($ignorer, ForumThreadUpdated::class);
        Notification::assertSentTo($mentioned, ForumPostMentioned::class);
        Notification::assertNotSentTo($quiet, ForumPostMentioned::class);

        $this->assertDatabaseHas('forum_post_mentions', [
            'forum_post_id' => $reply->id,
            'mentioned_user_id' => $mentioned->id,
        ]);
        $this->assertDatabaseHas('forum_post_mentions', [
            'forum_post_id' => $reply->id,
            'mentioned_user_id' => $quiet->id,
        ]);
    }

    public function test_a_guest_sees_no_ignore_choice_and_the_list_hides_when_the_forum_is_off(): void
    {
        $member = User::factory()->create();

        $this->get(route('members.show', $member))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('ignore', null));

        $this->put(route('forum.ignores.store', $member))->assertRedirect(route('login'));

        $sections = WebsiteSections::defaults();
        $sections[WebsiteSections::FORUM] = false;
        SystemSetting::set('website_sections', $sections);

        $viewer = User::factory()->create();

        $this->actingAs($viewer)
            ->get(route('privacy.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('ignored', null));

        $this->actingAs($viewer)
            ->get(route('members.show', $member))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('ignore', null));
    }

    private function reply(User $author, string $body): ForumPost
    {
        return ForumPost::create([
            'forum_thread_id' => $this->thread->id,
            'user_id' => $author->id,
            'body' => $body,
        ]);
    }
}
