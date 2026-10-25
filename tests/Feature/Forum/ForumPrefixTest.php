<?php

namespace Tests\Feature\Forum;

use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumPrefix;
use App\Models\ForumThread;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * One staff-defined label on a thread (docs/forum-prefixes.md).
 */
class ForumPrefixTest extends TestCase
{
    use RefreshDatabase;

    private ForumCategory $category;

    private ForumBoard $board;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->category = ForumCategory::create([
            'title' => 'General',
            'slug' => 'general',
            'description' => 'Chat',
            'position' => 1,
        ]);

        $this->board = ForumBoard::create([
            'forum_category_id' => $this->category->id,
            'title' => 'Help',
            'slug' => 'help-board',
            'description' => 'Questions',
            'position' => 1,
        ]);
    }

    public function test_staff_define_a_prefix_and_a_moderator_cannot(): void
    {
        $admin = $this->staff('admin');

        $this->actingAs($admin)->post(route('acp.forums.prefixes.store'), [
            'name' => 'Help',
            'color' => 'sky',
            'boards' => [$this->board->id],
        ])->assertRedirect(route('acp.forums.prefixes.index'));

        $prefix = ForumPrefix::query()->where('slug', 'help')->first();

        $this->assertNotNull($prefix);
        $this->assertTrue($prefix->boards()->whereKey($this->board->id)->exists());

        $this->actingAs($this->staff('moderator'))
            ->post(route('acp.forums.prefixes.store'), [
                'name' => 'Bug',
                'color' => 'rose',
                'boards' => [$this->board->id],
            ])
            ->assertForbidden();

        $this->actingAs($admin)->put(route('acp.forums.boards.update', ['board' => $this->board->id]), [
            'forum_category_id' => $this->category->id,
            'title' => 'Help',
            'slug' => 'help-board',
            'description' => 'Questions',
            'prefixes_required' => true,
        ])->assertRedirect();

        $this->assertTrue($this->board->fresh()->prefixes_required);
    }

    public function test_a_new_thread_must_pick_a_prefix_the_board_offers(): void
    {
        $prefix = $this->prefix('Help', 'help');
        $other = $this->prefix('News', 'news');
        $elsewhere = ForumBoard::create([
            'forum_category_id' => $this->category->id,
            'title' => 'News',
            'slug' => 'news-board',
            'description' => 'News',
            'position' => 2,
        ]);
        $other->boards()->sync([$elsewhere->id]);

        $this->board->forceFill(['prefixes_required' => true])->save();
        $member = User::factory()->create();

        $this->actingAs($member)
            ->post(route('forum.threads.store', $this->board), [
                'title' => 'Where do I start?',
                'body' => '<p>Hello</p>',
            ])
            ->assertSessionHasErrors('prefix');

        $this->actingAs($member)
            ->post(route('forum.threads.store', $this->board), [
                'title' => 'Where do I start?',
                'body' => '<p>Hello</p>',
                'prefix' => (string) $other->id,
            ])
            ->assertSessionHasErrors('prefix');

        $this->actingAs($member)
            ->post(route('forum.threads.store', $this->board), [
                'title' => 'Where do I start?',
                'body' => '<p>Hello</p>',
                'prefix' => (string) $prefix->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('forum_threads', [
            'title' => 'Where do I start?',
            'forum_prefix_id' => $prefix->id,
        ]);
    }

    public function test_a_board_that_requires_a_prefix_but_offers_none_still_accepts_a_thread(): void
    {
        $this->board->forceFill(['prefixes_required' => true])->save();

        $this->actingAs(User::factory()->create())
            ->post(route('forum.threads.store', $this->board), [
                'title' => 'Anything goes',
                'body' => '<p>Hello</p>',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('forum_threads', [
            'title' => 'Anything goes',
            'forum_prefix_id' => null,
        ]);
    }

    public function test_the_board_lists_the_label_and_filters_by_it(): void
    {
        $help = $this->prefix('Help', 'help');
        $bug = $this->prefix('Bug', 'bug');
        $this->thread(User::factory()->create(), 'Need a hand', $help);
        $this->thread(User::factory()->create(), 'Something broke', $bug);

        $this->get(route('forum.boards.show', $this->board))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.prefix', null)
                ->has('threads.data', 2)
                ->where('prefixes.0.slug', 'help')
                ->where('prefixes.1.slug', 'bug'));

        $this->get(route('forum.boards.show', ['board' => $this->board->slug, 'prefix' => 'help']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.prefix', 'help')
                ->has('threads.data', 1)
                ->where('threads.data.0.title', 'Need a hand')
                ->where('threads.data.0.prefix.slug', 'help'));

        $this->get(route('forum.boards.show', ['board' => $this->board->slug, 'prefix' => 'missing']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.prefix', null)
                ->has('threads.data', 2));
    }

    public function test_the_forum_home_and_the_thread_show_the_label(): void
    {
        $author = User::factory()->create();
        $prefix = $this->prefix('Help', 'help');
        $thread = $this->thread($author, 'Need a hand', $prefix);

        ForumPost::create([
            'forum_thread_id' => $thread->id,
            'user_id' => $author->id,
            'body' => '<p>Hello</p>',
        ]);

        $this->get(route('forum.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('categories.0.boards.0.latest_thread.prefix.slug', 'help')
                ->where('trendingThreads.0.prefix.slug', 'help')
                ->where('latestPosts.0.prefix.slug', 'help'));

        $this->get(route('forum.threads.show', [$this->board, $thread]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('thread.prefix.slug', 'help')
                ->where('prefixes.0.slug', 'help'));
    }

    public function test_the_author_and_a_moderator_can_change_the_prefix(): void
    {
        $help = $this->prefix('Help', 'help');
        $bug = $this->prefix('Bug', 'bug');
        $author = User::factory()->create();
        $thread = $this->thread($author, 'Need a hand', $help);

        $this->actingAs($author)->put(route('forum.threads.update', [$this->board, $thread]), [
            'title' => 'Need a hand',
            'prefix' => (string) $bug->id,
        ])->assertRedirect();

        $this->assertSame($bug->id, $thread->fresh()->forum_prefix_id);

        $this->actingAs($author)->put(route('forum.threads.update', [$this->board, $thread]), [
            'title' => 'Need a hand',
            'prefix' => '',
        ])->assertRedirect();

        $this->assertNull($thread->fresh()->forum_prefix_id);

        $thread->refresh();
        $thread->forceFill(['is_locked' => true, 'forum_prefix_id' => $help->id])->save();

        $this->actingAs($author)->put(route('forum.threads.update', [$this->board, $thread]), [
            'title' => 'Need a hand',
            'prefix' => (string) $bug->id,
        ])->assertForbidden();

        $this->assertSame($help->id, $thread->fresh()->forum_prefix_id);

        $this->actingAs($this->staff('moderator'))->put(route('forum.threads.update', [$this->board, $thread]), [
            'title' => 'Need a hand',
            'prefix' => (string) $bug->id,
        ])->assertRedirect();

        $this->assertSame($bug->id, $thread->fresh()->forum_prefix_id);

        $this->actingAs(User::factory()->create())->put(route('forum.threads.update', [$this->board, $thread]), [
            'title' => 'Need a hand',
            'prefix' => '',
        ])->assertForbidden();
    }

    public function test_removing_a_prefix_or_a_board_clears_it_from_the_thread(): void
    {
        $prefix = $this->prefix('Help', 'help');
        $thread = $this->thread(User::factory()->create(), 'Need a hand', $prefix);
        $admin = $this->staff('admin');

        $this->actingAs($admin)->put(route('acp.forums.prefixes.update', $prefix), [
            'name' => 'Help',
            'slug' => 'help',
            'color' => 'sky',
            'position' => 1,
            'boards' => [],
        ])->assertRedirect(route('acp.forums.prefixes.index'));

        $this->assertNull($thread->fresh()->forum_prefix_id);

        $thread->forceFill(['forum_prefix_id' => $prefix->id])->save();
        $prefix->boards()->sync([$this->board->id]);

        $this->actingAs($admin)
            ->delete(route('acp.forums.prefixes.destroy', $prefix))
            ->assertRedirect(route('acp.forums.prefixes.index'));

        $this->assertNull($thread->fresh()->forum_prefix_id);
        $this->assertSame('Need a hand', $thread->fresh()->title);
    }

    public function test_the_api_accepts_a_slug_and_refuses_another_boards_prefix(): void
    {
        $help = $this->prefix('Help', 'help');
        $news = $this->prefix('News', 'news');
        $elsewhere = ForumBoard::create([
            'forum_category_id' => $this->category->id,
            'title' => 'News',
            'slug' => 'news-board',
            'description' => 'News',
            'position' => 2,
        ]);
        $news->boards()->sync([$elsewhere->id]);

        $this->board->forceFill(['prefixes_required' => true])->save();
        $member = User::factory()->create();
        Sanctum::actingAs($member, ['*']);

        $this->postJson(route('api.v1.forum.threads.store', $this->board), [
            'title' => 'From the API',
            'body' => '<p>Hello</p>',
        ])->assertUnprocessable()->assertJsonValidationErrors('prefix');

        $this->postJson(route('api.v1.forum.threads.store', $this->board), [
            'title' => 'From the API',
            'body' => '<p>Hello</p>',
            'prefix' => 'news',
        ])->assertUnprocessable()->assertJsonValidationErrors('prefix');

        $this->postJson(route('api.v1.forum.threads.store', $this->board), [
            'title' => 'From the API',
            'body' => '<p>Hello</p>',
            'prefix' => 'help',
        ])->assertCreated()->assertJsonPath('thread.prefix.slug', 'help');

        $thread = ForumThread::query()->where('title', 'From the API')->firstOrFail();

        $this->patchJson(route('api.v1.forum.threads.update', [$this->board, $thread]), [
            'title' => 'From the API',
        ])->assertOk()->assertJsonPath('data.prefix.slug', 'help');

        $this->patchJson(route('api.v1.forum.threads.update', [$this->board, $thread]), [
            'title' => 'From the API',
            'prefix' => null,
        ])->assertOk()->assertJsonPath('data.prefix', null);

        $this->assertNull($thread->fresh()->forum_prefix_id);
        $this->assertSame($help->id, ForumPrefix::query()->where('slug', 'help')->value('id'));
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function prefix(string $name, string $slug): ForumPrefix
    {
        $prefix = ForumPrefix::create([
            'name' => $name,
            'slug' => $slug,
            'color' => 'sky',
            'position' => ForumPrefix::query()->count() + 1,
        ]);

        $prefix->boards()->sync([$this->board->id]);

        return $prefix;
    }

    private function thread(User $author, string $title, ?ForumPrefix $prefix = null): ForumThread
    {
        return ForumThread::create([
            'forum_board_id' => $this->board->id,
            'forum_prefix_id' => $prefix?->id,
            'user_id' => $author->id,
            'title' => $title,
            'slug' => str($title)->slug()->toString(),
            'excerpt' => $title,
            'is_locked' => false,
            'is_pinned' => false,
            'is_published' => true,
            'views' => 1,
            'last_posted_at' => now(),
            'last_post_user_id' => $author->id,
        ]);
    }
}
