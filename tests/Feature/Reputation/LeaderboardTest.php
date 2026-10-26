<?php

namespace Tests\Feature\Reputation;

use App\Models\Badge;
use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumIgnore;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\ReputationEvent;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\Reputation\Leaderboard;
use App\Support\WebsiteSections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The public leaderboard and its activity feed (docs/leaderboard.md).
 */
class LeaderboardTest extends TestCase
{
    use RefreshDatabase;

    private ForumBoard $board;

    protected function setUp(): void
    {
        parent::setUp();

        $category = ForumCategory::create(['title' => 'General', 'slug' => 'general', 'description' => 'Chat', 'position' => 1]);
        $this->board = ForumBoard::create([
            'forum_category_id' => $category->id,
            'title' => 'The lounge',
            'slug' => 'the-lounge',
            'description' => 'Chat',
            'position' => 1,
        ]);
    }

    /**
     * @param  array<string, mixed>  $over
     */
    private function thread(User $author, string $title, array $over = []): ForumThread
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
    private function reply(ForumThread $thread, User $author, string $body, array $over = []): ForumPost
    {
        $post = new ForumPost([
            'forum_thread_id' => $thread->id,
            'user_id' => $author->id,
            'body' => '<p>'.$body.'</p>',
        ]);
        $post->forceFill($over)->save();

        return $post;
    }

    private function award(User $user, ForumPost $post, string $event = 'forum_post_created', int $points = 5): ReputationEvent
    {
        return ReputationEvent::create([
            'user_id' => $user->id,
            'event' => $event,
            'points' => $points,
            'source_type' => $post->getMorphClass(),
            'source_id' => $post->id,
        ]);
    }

    #[Test]
    public function the_leaderboard_ranks_members_who_have_earned_reputation(): void
    {
        $this->get(route('leaderboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Leaderboard')
                ->has('standings', 0)
                ->has('activity', 0));

        $ada = User::factory()->create([
            'nickname' => 'Ada',
            'reputation_points' => 50,
            'avatar_url' => 'javascript:alert(1)',
        ]);
        $bea = User::factory()->create([
            'nickname' => 'Bea',
            'reputation_points' => 50,
            'avatar_url' => 'https://example.com/bea.png',
        ]);
        User::factory()->create(['nickname' => 'Cara', 'reputation_points' => 40]);
        User::factory()->create(['nickname' => 'Quiet', 'reputation_points' => 0]);
        User::factory()->create(['nickname' => 'Banned Spammer', 'reputation_points' => 1000, 'is_banned' => true]);

        $badge = Badge::create([
            'name' => 'Regular',
            'slug' => 'regular',
            'description' => 'Posts often',
            'points_required' => 10,
            'is_active' => true,
        ]);
        $ada->badges()->attach($badge->id, ['awarded_at' => now()]);

        foreach (range(1, Leaderboard::STANDINGS) as $number) {
            User::factory()->create([
                'nickname' => sprintf('Crowd %02d', $number),
                'reputation_points' => $number,
            ]);
        }

        Cache::flush();

        $this->get(route('leaderboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('standings', Leaderboard::STANDINGS)
                ->where('standings.0.nickname', 'Ada')
                ->where('standings.0.rank', 1)
                ->where('standings.0.reputation', 50)
                ->where('standings.0.avatar_url', null)
                ->where('standings.0.badges.0.name', 'Regular')
                ->where('standings.1.nickname', 'Bea')
                ->where('standings.1.rank', 2)
                ->where('standings.1.avatar_url', 'https://example.com/bea.png')
                ->where('standings.2.nickname', 'Cara')
                ->where('standings.2.rank', 3)
                ->where('standings.2.reputation', 40)
                ->where('standings.3.nickname', 'Crowd 20')
                ->where('standings.19.nickname', 'Crowd 04'))
            ->assertDontSee('Banned Spammer', false)
            ->assertDontSee('Quiet', false)
            ->assertDontSee('Crowd 01', false)
            ->assertDontSee('Crowd 02', false)
            ->assertDontSee('Crowd 03', false);

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee('<loc>'.e(route('leaderboard')).'</loc>', false);
    }

    #[Test]
    public function the_activity_feed_lists_only_posts_that_are_still_public(): void
    {
        $author = User::factory()->create(['nickname' => 'Ada']);
        $thread = $this->thread($author, 'Hello there');

        $oldest = $this->reply($thread, $author, 'Line 01');
        $this->award($author, $oldest);

        $second = $this->reply($thread, $author, 'Line 02');
        $this->award($author, $second);

        foreach (range(3, Leaderboard::ACTIVITY) as $number) {
            $this->award($author, $this->reply($thread, $author, sprintf('Line %02d', $number)));
        }

        $newest = $this->reply($thread, $author, 'A note &amp; more <strong>today</strong>');
        $newestEvent = $this->award($author, $newest);

        $held = $this->reply($thread, $author, 'Held back');
        $held->forceFill(['held_at' => now()])->save();
        $this->award($author, $held);

        $removed = $this->reply($thread, $author, 'Taken down');
        $this->award($author, $removed);
        $removed->delete();

        $hidden = $this->thread($author, 'Not published', ['is_published' => false]);
        $this->award($author, $this->reply($hidden, $author, 'Still private'));

        $banned = User::factory()->create(['nickname' => 'Banned Spammer', 'is_banned' => true]);
        $this->award($banned, $this->reply($thread, $banned, 'Spam note'));

        $secret = $this->reply($thread, $author, 'Secret adjustment');
        $this->award($author, $secret, 'internal_adjustment', 99);

        $viewer = User::factory()->create();
        ForumIgnore::query()->create([
            'user_id' => $viewer->id,
            'ignored_user_id' => $author->id,
        ]);

        $this->actingAs($viewer)
            ->get(route('leaderboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('activity', Leaderboard::ACTIVITY)
                ->where('activity.0.id', $newestEvent->id)
                ->where('activity.0.member.nickname', 'Ada')
                ->where('activity.0.points', 5)
                ->where('activity.0.label', 'Posted in the forum')
                ->where('activity.0.post.excerpt', 'A note & more today')
                ->where('activity.0.post.thread', 'Hello there')
                ->where('activity.0.post.url', route('forum.threads.show', ['board' => $this->board, 'thread' => $thread, 'page' => 3]).'#post-'.$newest->id)
                ->where('activity.19.post.excerpt', 'Line 02')
                ->where('activity.19.post.url', route('forum.threads.show', [$this->board, $thread]).'#post-'.$second->id))
            ->assertDontSee('Held back', false)
            ->assertDontSee('Taken down', false)
            ->assertDontSee('Still private', false)
            ->assertDontSee('Spam note', false)
            ->assertDontSee('internal_adjustment', false)
            ->assertDontSee('Line 01', false);
    }

    #[Test]
    public function the_leaderboard_is_missing_when_the_forum_is_off(): void
    {
        $sections = WebsiteSections::defaults();
        $sections[WebsiteSections::FORUM] = false;
        SystemSetting::set('website_sections', $sections);
        Cache::flush();

        $this->get(route('leaderboard'))->assertNotFound();

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertDontSee('<loc>'.e(route('leaderboard')).'</loc>', false);
    }
}
