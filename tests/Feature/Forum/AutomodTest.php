<?php

namespace Tests\Feature\Forum;

use App\Events\ForumPostCreated;
use App\Models\AutomodRule;
use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\User;
use App\Notifications\ForumPostMentioned;
use App\Notifications\ForumThreadUpdated;
use App\Support\Forum\ForumReview;
use App\Support\Forum\ForumSubmissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The forum's automatic moderation (docs/automod.md): what each rule looks for, who it is for, what a held post is and is not, and
 * what happens when a moderator approves it.
 */
class AutomodTest extends TestCase
{
    use RefreshDatabase;

    private ForumBoard $board;

    private ForumThread $thread;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'editor', 'moderator'] as $role) {
            Role::findOrCreate($role);
        }

        $category = ForumCategory::create(['title' => 'General', 'slug' => 'general', 'description' => 'Chat', 'position' => 1]);
        $this->board = ForumBoard::create(['forum_category_id' => $category->id, 'title' => 'The lounge', 'slug' => 'the-lounge', 'description' => 'Chat', 'position' => 1]);
        $this->thread = $this->makeThread($this->member(), 'Welcome');
    }

    /** A member whose account is not new, unless it is made so. */
    private function member(array $attributes = []): User
    {
        return User::factory()->create($attributes + ['created_at' => now()->subDays(60)]);
    }

    private function staff(string $role = 'moderator'): User
    {
        $user = $this->member();
        $user->assignRole($role);

        return $user;
    }

    private function makeThread(User $author, string $title): ForumThread
    {
        return ForumThread::create([
            'forum_board_id' => $this->board->id,
            'user_id' => $author->id,
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::random(4),
            'excerpt' => $title,
            'is_locked' => false,
            'is_pinned' => false,
            'is_published' => true,
            'views' => 0,
            'last_posted_at' => now()->subDay(),
            'last_post_user_id' => $author->id,
        ]);
    }

    private function reply(User $who, string $html): TestResponse
    {
        return $this->actingAs($who)->post(route('forum.posts.store', [$this->board, $this->thread]), ['body' => $html]);
    }

    private function held(): ?ForumPost
    {
        return ForumPost::query()->withoutGlobalScope(ForumPost::VISIBLE)->whereNotNull('held_at')->latest('id')->first();
    }

    // --- Nothing in the way ---------------------------------------------------------------------------------------------------

    #[Test]
    public function a_reply_that_breaks_no_rule_is_posted_as_it_always_was(): void
    {
        Notification::fake();
        $author = $this->member();

        $this->reply($author, '<p>A perfectly ordinary reply.</p>')->assertRedirect()->assertSessionHas('success', 'Reply posted successfully.');

        $post = ForumPost::query()->sole();
        $this->assertNull($post->held_at);
        $this->assertSame(5, $author->fresh()->reputation_points);
        $this->assertSame($author->id, $this->thread->fresh()->last_post_user_id);
    }

    // --- Phrases --------------------------------------------------------------------------------------------------------------

    #[Test]
    public function a_phrase_in_a_reply_holds_it_whatever_the_case_and_the_markup(): void
    {
        $rule = AutomodRule::factory()->words(['cheap watches'])->create(['name' => 'Watch spam']);

        $this->reply($this->member(), '<p>Buy <b>CHEAP</b> watches here</p>')
            ->assertRedirect()
            ->assertSessionHas('success', 'Your reply has been sent for review. It will appear when a moderator approves it.');

        $held = $this->held();

        $this->assertNotNull($held);
        $this->assertSame($rule->id, $held->held_by_rule_id);
        $this->assertSame('Watch spam: contains "cheap watches"', $held->held_reason);
        $this->assertSame(0, ForumPost::query()->count(), 'nothing that lists or counts replies sees it');
    }

    #[Test]
    public function whole_words_are_matched_as_whole_words(): void
    {
        AutomodRule::factory()->words(['ass'], wholeWords: true)->create();

        $this->reply($this->member(), '<p>The class was a great success, I assume</p>');
        $this->assertNull($this->held(), 'a word that ends, or starts, like the phrase is not the phrase');

        $this->reply($this->member(), '<p>What an ass.</p>');
        $this->assertNotNull($this->held());
    }

    #[Test]
    public function a_phrase_is_found_across_paragraphs_and_in_the_characters_the_markup_stands_for(): void
    {
        AutomodRule::factory()->words(['buy now', 'rock & roll'])->create();

        $this->reply($this->member(), '<p>Please buy</p><p>now</p>');
        $this->assertNotNull($this->held(), 'the end of a paragraph is a space');
        $first = $this->held();

        $this->reply($this->member(), '<p>Rock &amp; Roll forever</p>');
        $this->assertNotSame($first->id, $this->held()?->id, 'an entity is the character it stands for');
    }

    #[Test]
    public function a_blank_phrase_left_in_a_rule_matches_nothing(): void
    {
        // The screen never stores one; a rule edited by hand might have one.
        AutomodRule::factory()->words(['', '   ', 'scam'])->create();

        $this->reply($this->member(), '<p>A perfectly friendly hello</p>');
        $this->assertNull($this->held());

        $this->reply($this->member(), '<p>This is a scam</p>');
        $this->assertNotNull($this->held());
    }

    #[Test]
    public function the_title_of_a_thread_is_looked_at_as_well_as_what_it_says(): void
    {
        AutomodRule::factory()->words(['free money'])->create();

        $this->actingAs($this->member())->post(route('forum.threads.store', $this->board), ['title' => 'Get FREE money now', 'body' => '<p>Details inside.</p>'])
            ->assertRedirect(route('forum.boards.show', $this->board));

        $this->assertSame(1, ForumThread::query()->whereNotNull('held_at')->count());
    }

    // --- Links ----------------------------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{string, int, array<int, string>, bool}>
     */
    public static function linkCases(): array
    {
        return [
            'more links than allowed' => ['<p>https://a.example https://b.example https://c.example</p>', 2, [], true],
            'exactly as many as allowed' => ['<p>https://a.example and https://b.example</p>', 2, [], false],
            'the same link twice is one' => ['<p>https://a.example https://a.example https://a.example</p>', 2, [], false],
            'a link and its text are one' => ['<p><a href="https://a.example/x">https://a.example/x</a> <a href="https://b.example">b</a></p>', 2, [], false],
            'a link in an href alone counts' => ['<p><a href="https://a.example">one</a> <a href="https://b.example">two</a> <a href="https://c.example">three</a></p>', 2, [], true],
            'a link with www and no scheme counts' => ['<p>www.a.example www.b.example www.c.example</p>', 2, [], true],
            'no links at all' => ['<p>No links.</p>', 0, [], false],
            'a blocked domain' => ['<p>See https://bad.example/page</p>', 10, ['bad.example'], true],
            'a subdomain of a blocked domain' => ['<p>See https://shop.bad.example/page</p>', 10, ['bad.example'], true],
            'a blocked domain with a www' => ['<p>See https://www.bad.example/page</p>', 10, ['bad.example'], true],
            'a domain that only ends the same way' => ['<p>See https://notbad.example/page</p>', 10, ['bad.example'], false],
            'a blocked domain written as an address' => ['<p>See https://bad.example/page</p>', 10, ['https://www.bad.example/some/path'], true],
            'a blocked domain in capitals' => ['<p>See HTTPS://BAD.EXAMPLE/Page</p>', 10, ['Bad.Example'], true],
            'a blocked domain at the end of a sentence' => ['<p>Visit http://bad.example.</p>', 10, ['bad.example'], true],
            'a blocked domain in brackets' => ['<p>(see https://bad.example)</p>', 10, ['bad.example'], true],
            'a blocked domain with a full stop after the name and a path' => ['<p>See https://bad.example./offer</p>', 10, ['bad.example'], true],
            'a subdomain with a full stop after the name' => ['<p>See https://shop.bad.example./offer</p>', 10, ['bad.example'], true],
            'a www and a full stop after the name' => ['<p>See https://www.bad.example./offer</p>', 10, ['bad.example'], true],
            'a full stop after the name and a port' => ['<p>See https://bad.example.:8080/offer</p>', 10, ['bad.example'], true],
            'a full stop after the name in an href' => ['<p><a href="https://bad.example./offer">offer</a></p>', 10, ['bad.example'], true],
            'a full stop after the name and no scheme' => ['<p>See www.bad.example./offer</p>', 10, ['bad.example'], true],
            'a blocked domain written with a full stop after it' => ['<p>See https://bad.example/offer</p>', 10, ['bad.example.'], true],
            'a blocked address written with a full stop after the name' => ['<p>See https://shop.bad.example/offer</p>', 10, ['https://www.bad.example./some/path'], true],
            'a domain with a full stop that only ends the same way' => ['<p>See https://notbad.example./offer</p>', 10, ['bad.example'], false],
            'a blocked domain that is only a full stop blocks nothing' => ['<p>See https://good.example./offer</p>', 10, ['.'], false],
            'a link and the same link with punctuation after it are one' => ['<p>https://a.example, https://a.example. https://a.example! and https://b.example</p>', 2, [], false],
        ];
    }

    /**
     * @param  array<int, string>  $blocked
     */
    #[Test]
    #[DataProvider('linkCases')]
    public function links_are_counted_and_checked_against_blocked_domains(string $html, int $max, array $blocked, bool $held): void
    {
        AutomodRule::factory()->links($max, $blocked)->create();

        $this->reply($this->member(), $html);

        $this->assertSame($held, $this->held() !== null);
    }

    #[Test]
    public function a_rule_about_links_with_no_limit_looks_only_at_the_domains(): void
    {
        AutomodRule::factory()->links(null, ['bad.example'])->create();

        $this->reply($this->member(), '<p>'.implode(' ', array_map(fn ($n) => "https://ok{$n}.example", range(1, 30))).'</p>');

        $this->assertNull($this->held());
    }

    #[Test]
    public function a_blank_domain_left_in_a_rule_blocks_nothing(): void
    {
        // The screen never stores one; a rule edited by hand might have one. A link with no host in it is not a link to a blank domain.
        AutomodRule::factory()->links(null, ['', ' ', 'bad.example'])->create();

        $this->reply($this->member(), '<p>See http://example.com./page</p>');
        $this->assertNull($this->held());

        $this->reply($this->member(), '<p><a href="http://">nowhere</a></p>');
        $this->assertNull($this->held());

        $this->reply($this->member(), '<p>See http://bad.example/page</p>');
        $this->assertNotNull($this->held());
    }

    // --- The same text again, and too fast ------------------------------------------------------------------------------------

    #[Test]
    public function the_same_text_posted_again_is_held_and_a_different_one_is_not(): void
    {
        AutomodRule::factory()->duplicates(60, 1)->create();
        $author = $this->member();

        $this->reply($author, '<p>Check out my shop</p>');
        $this->assertNull($this->held(), 'the first time is just a post');

        $this->reply($author, '<p>A different thing entirely</p>');
        $this->assertNull($this->held());

        $this->reply($author, "<p>CHECK   out my\nshop</p>");
        $this->assertNotNull($this->held(), 'the same words, however they are spaced or capitalised');
        $this->assertStringContainsString('the same text was posted 1 time in the last 60 minutes', (string) $this->held()?->held_reason);
    }

    #[Test]
    public function a_text_has_to_have_been_posted_as_many_times_as_the_rule_says(): void
    {
        AutomodRule::factory()->duplicates(60, 2)->create();
        $author = $this->member();

        foreach (range(1, 3) as $time) {
            $this->reply($author, '<p>Buy now</p>');

            $this->assertSame($time === 3, $this->held() !== null, "post number {$time}");
        }
    }

    #[Test]
    public function a_text_posted_longer_ago_than_the_window_is_not_a_repeat(): void
    {
        AutomodRule::factory()->duplicates(30, 1)->create();
        $author = $this->member();

        ForumPost::create(['forum_thread_id' => $this->thread->id, 'user_id' => $author->id, 'body' => '<p>Buy now</p>'])
            ->forceFill(['created_at' => now()->subMinutes(45)])->save();

        $this->reply($author, '<p>Buy now</p>');

        $this->assertNull($this->held());
    }

    #[Test]
    public function a_copy_that_was_held_is_still_a_copy(): void
    {
        AutomodRule::factory()->duplicates(60, 1)->create();
        $author = $this->member();

        ForumPost::create(['forum_thread_id' => $this->thread->id, 'user_id' => $author->id, 'body' => '<p>Buy now</p>'])
            ->forceFill(['held_at' => now(), 'held_reason' => 'Earlier'])->save();

        $this->reply($author, '<p>Buy now</p>');

        $this->assertSame(2, ForumPost::query()->withoutGlobalScope(ForumPost::VISIBLE)->whereNotNull('held_at')->count(), 'the earlier copy was held, and so is this one');
    }

    #[Test]
    public function somebody_elses_text_is_not_a_repeat_of_yours(): void
    {
        AutomodRule::factory()->duplicates(60, 1)->create();

        $this->reply($this->member(), '<p>Great thread, thanks!</p>');
        $this->reply($this->member(), '<p>Great thread, thanks!</p>');

        $this->assertNull($this->held());
    }

    #[Test]
    public function too_many_posts_in_a_short_time_are_held_and_the_held_ones_count(): void
    {
        AutomodRule::factory()->rate(3, 10)->create();
        $author = $this->member();

        foreach (['one', 'two', 'three'] as $word) {
            $this->reply($author, "<p>Post {$word}</p>");
            $this->assertNull($this->held(), $word);
        }

        $this->reply($author, '<p>Post four</p>');
        $this->assertSame('Too fast: 3 posts in the last 10 minutes', $this->held()?->held_reason);

        $this->reply($author, '<p>Post five</p>');
        $this->assertSame(2, ForumPost::query()->withoutGlobalScope(ForumPost::VISIBLE)->whereNotNull('held_at')->count(), 'a spammer\'s held posts are what the next one is counted against');
    }

    #[Test]
    public function a_held_post_counts_towards_the_rate_on_its_own(): void
    {
        AutomodRule::factory()->rate(3, 10)->create();
        $author = $this->member();

        foreach ([1, 2] as $n) {
            ForumPost::create(['forum_thread_id' => $this->thread->id, 'user_id' => $author->id, 'body' => "<p>Post {$n}</p>"]);
        }

        ForumPost::create(['forum_thread_id' => $this->thread->id, 'user_id' => $author->id, 'body' => '<p>Post 3</p>'])
            ->forceFill(['held_at' => now(), 'held_reason' => 'Earlier'])->save();

        $this->reply($author, '<p>Post 4</p>');

        $this->assertSame('Too fast: 3 posts in the last 10 minutes', $this->held()?->held_reason, 'two that are out and one that is held make three');
    }

    #[Test]
    public function other_peoples_posts_do_not_count_towards_your_rate(): void
    {
        AutomodRule::factory()->rate(3, 10)->create();
        $busy = $this->member();

        foreach (range(1, 4) as $n) {
            ForumPost::create(['forum_thread_id' => $this->thread->id, 'user_id' => $busy->id, 'body' => "<p>Busy {$n}</p>"]);
        }

        $this->reply($this->member(), '<p>My first post</p>');

        $this->assertNull($this->held());
    }

    #[Test]
    public function posts_older_than_the_window_do_not_count_towards_the_rate(): void
    {
        AutomodRule::factory()->rate(2, 10)->create();
        $author = $this->member();

        foreach ([1, 2, 3] as $n) {
            ForumPost::create(['forum_thread_id' => $this->thread->id, 'user_id' => $author->id, 'body' => "<p>Old {$n}</p>"])
                ->forceFill(['created_at' => now()->subMinutes(30)])->save();
        }

        $this->reply($author, '<p>A new one</p>');

        $this->assertNull($this->held());
    }

    // --- Who is checked -------------------------------------------------------------------------------------------------------

    #[Test]
    public function staff_are_never_checked(): void
    {
        AutomodRule::factory()->words(['cheap watches'])->create();

        foreach (['admin', 'editor', 'moderator'] as $role) {
            $this->reply($this->staff($role), '<p>cheap watches</p>');
        }

        $this->assertNull($this->held());
        $this->assertSame(3, ForumPost::query()->count());
    }

    #[Test]
    public function a_rule_for_new_members_leaves_the_older_ones_alone(): void
    {
        AutomodRule::factory()->words(['cheap watches'])->onlyForNewMembers(7)->create();

        $this->reply($this->member(['created_at' => now()->subDays(8)]), '<p>cheap watches</p>');
        $this->assertNull($this->held(), 'eight days old');

        $this->reply($this->member(['created_at' => now()->subDays(6)]), '<p>cheap watches</p>');
        $this->assertNotNull($this->held(), 'six days old');
    }

    #[Test]
    public function a_rule_that_is_off_is_not_looked_at(): void
    {
        AutomodRule::factory()->words(['cheap watches'])->off()->create();

        $this->reply($this->member(), '<p>cheap watches</p>');

        $this->assertNull($this->held());
    }

    // --- Refusing -------------------------------------------------------------------------------------------------------------

    #[Test]
    public function a_rule_that_refuses_stops_the_reply_and_says_so(): void
    {
        $rule = AutomodRule::factory()->words(['cheap watches'])->rejecting()->create();

        $this->reply($this->member(), '<p>cheap watches</p>')->assertSessionHasErrors(['body' => ForumSubmissions::REJECTED]);

        $this->assertSame(0, ForumPost::query()->withoutGlobalScope(ForumPost::VISIBLE)->count(), 'nothing was kept');
        $this->assertSame(1, $rule->fresh()->hits);
    }

    #[Test]
    public function a_rule_that_refuses_wins_over_one_that_holds_whichever_comes_first(): void
    {
        $holds = AutomodRule::factory()->words(['watches'])->create(['name' => 'Holds']);
        $refuses = AutomodRule::factory()->words(['cheap'])->rejecting()->create(['name' => 'Refuses']);

        $this->reply($this->member(), '<p>cheap watches</p>')->assertSessionHasErrors('body');

        $this->assertSame(0, $holds->fresh()->hits, 'only the rule that decided is counted');
        $this->assertSame(1, $refuses->fresh()->hits);
        $this->assertNotNull($refuses->fresh()->last_hit_at);
    }

    #[Test]
    public function only_the_rule_that_held_it_is_counted(): void
    {
        $first = AutomodRule::factory()->words(['watches'])->create(['name' => 'First']);
        $second = AutomodRule::factory()->words(['cheap'])->create(['name' => 'Second']);

        $this->reply($this->member(), '<p>cheap watches</p>');

        $this->assertSame(1, $first->fresh()->hits);
        $this->assertSame(0, $second->fresh()->hits);
        $this->assertSame($first->id, $this->held()?->held_by_rule_id, 'the first to hold it');
    }

    // --- What a held reply is, and is not -------------------------------------------------------------------------------------

    #[Test]
    public function a_held_reply_tells_nobody_and_earns_nothing_and_changes_nothing(): void
    {
        Notification::fake();
        Event::fake([ForumPostCreated::class]);
        AutomodRule::factory()->words(['cheap watches'])->create();

        $follower = $this->member();
        $this->thread->subscribers()->attach($follower);
        $mentioned = $this->member(['nickname' => 'MentionedMember']);
        $author = $this->member();
        $before = $this->thread->fresh()->last_posted_at;

        $this->reply($author, '<p>cheap watches for @MentionedMember</p>');

        $this->assertNotNull($this->held());
        Notification::assertNothingSent();
        Event::assertNotDispatched(ForumPostCreated::class);
        $this->assertSame(0, $author->fresh()->reputation_points);
        $this->assertTrue($before->equalTo($this->thread->fresh()->last_posted_at), 'the thread is not bumped');
        $this->assertDatabaseCount('forum_post_mentions', 0);
        $this->assertDatabaseCount('forum_thread_reads', 0);
        $this->assertNotNull($mentioned);
    }

    #[Test]
    public function approving_it_does_all_of_that_then(): void
    {
        Notification::fake();
        Event::fake([ForumPostCreated::class]);
        AutomodRule::factory()->words(['cheap watches'])->create();

        $follower = $this->member();
        $this->thread->subscribers()->attach($follower);
        $mentioned = $this->member(['nickname' => 'MentionedMember']);
        $author = $this->member();

        $this->reply($author, '<p>cheap watches for @MentionedMember</p>');
        $held = $this->held();

        $this->assertTrue(app(ForumReview::class)->approveReply($held));

        $this->assertNull($held->fresh()->held_at);
        $this->assertNull($held->fresh()->held_reason);
        $this->assertNull($held->fresh()->held_by_rule_id);
        $this->assertSame(1, ForumPost::query()->count());
        Notification::assertSentTo($follower, ForumThreadUpdated::class);
        Notification::assertSentTo($mentioned, ForumPostMentioned::class);
        Event::assertDispatched(ForumPostCreated::class);
        $this->assertSame(5, $author->fresh()->reputation_points);
        $this->assertSame($author->id, $this->thread->fresh()->last_post_user_id);
        $this->assertDatabaseCount('forum_post_mentions', 1);
    }

    #[Test]
    public function approving_it_twice_does_it_once(): void
    {
        Notification::fake();
        AutomodRule::factory()->words(['cheap watches'])->create();
        $author = $this->member();
        $follower = $this->member();
        $this->thread->subscribers()->attach($follower);

        $this->reply($author, '<p>cheap watches</p>');
        $held = $this->held();

        $this->assertTrue(app(ForumReview::class)->approveReply($held));
        $told = Notification::sent($follower, ForumThreadUpdated::class)->count();
        $this->assertGreaterThan(0, $told);

        $this->assertFalse(app(ForumReview::class)->approveReply($held), 'somebody else has done it');

        $this->assertSame(5, $author->fresh()->reputation_points, 'earned once');
        $this->assertCount($told, Notification::sent($follower, ForumThreadUpdated::class), 'and the follower is told once');
    }

    #[Test]
    public function rejecting_it_removes_it_and_a_second_rejection_finds_nothing(): void
    {
        AutomodRule::factory()->words(['cheap watches'])->create();
        $this->reply($this->member(), '<p>cheap watches</p>');
        $held = $this->held();

        $this->assertTrue(app(ForumReview::class)->rejectReply($held));
        $this->assertFalse(app(ForumReview::class)->rejectReply($held));
        $this->assertSame(0, ForumPost::query()->withoutGlobalScope(ForumPost::VISIBLE)->count());
    }

    #[Test]
    public function the_author_sees_their_held_reply_marked_and_nobody_else_sees_it(): void
    {
        AutomodRule::factory()->words(['cheap watches'])->create(['name' => 'Watch spam']);
        $author = $this->member();
        $this->reply($author, '<p>cheap watches</p>');
        $this->reply($this->member(), '<p>A reply that is fine</p>');

        $posts = function (?User $viewer) {
            // A test stays signed in as the last one it acted as, so a guest has to be made.
            $this->app['auth']->forgetGuards();

            return collect(
                ($viewer ? $this->actingAs($viewer) : $this)->get(route('forum.threads.show', [$this->board, $this->thread]))->viewData('page')['props']['posts']['data'],
            );
        };

        $mine = $posts($author);
        $this->assertCount(2, $mine);
        $this->assertSame([true, false], $mine->pluck('held')->sort()->reverse()->values()->all());
        $this->assertNull($mine->firstWhere('held', true)['held_reason'], 'the author is not told which rule');

        $this->assertCount(1, $posts($this->member()), 'another member');
        $this->assertCount(1, $posts(null), 'a guest');

        $moderated = $posts($this->staff());
        $this->assertCount(2, $moderated);
        $this->assertSame('Watch spam: contains "cheap watches"', $moderated->firstWhere('held', true)['held_reason']);
    }

    #[Test]
    public function a_held_reply_is_not_counted_listed_or_the_latest(): void
    {
        AutomodRule::factory()->words(['cheap watches'])->create();
        $author = $this->member();
        $visible = ForumPost::create(['forum_thread_id' => $this->thread->id, 'user_id' => $this->member()->id, 'body' => '<p>Fine</p>']);

        $this->reply($author, '<p>cheap watches</p>');

        $this->assertSame(1, $this->thread->posts()->count());
        $this->assertSame($visible->id, $this->thread->latestPost()->first()->id);
        $this->assertSame(0, $author->forumPosts()->count());
    }

    #[Test]
    public function a_held_reply_is_not_found_by_searching_the_board(): void
    {
        AutomodRule::factory()->words(['cheap watches'])->create();
        $author = $this->member();
        $this->reply($author, '<p>cheap watches at zebracorn prices</p>');

        $other = $this->makeThread($this->member(), 'Another thread');
        ForumPost::create(['forum_thread_id' => $other->id, 'user_id' => $this->member()->id, 'body' => '<p>zebracorn</p>']);

        $found = function (?User $viewer) {
            $this->app['auth']->forgetGuards();

            return collect(
                ($viewer ? $this->actingAs($viewer) : $this)
                    ->get(route('forum.boards.show', ['board' => $this->board->slug, 'search' => 'zebracorn']))
                    ->viewData('page')['props']['threads']['data'],
            )->pluck('id')->sort()->values()->all();
        };

        $this->assertSame([$other->id], $found(null), 'a guest');
        $this->assertSame([$other->id], $found($this->member()), 'another member');
        $this->assertSame([$other->id], $found($author), 'its author');
        $this->assertSame([$other->id], $found($this->staff()), 'a moderator');

        $this->assertTrue(app(ForumReview::class)->approveReply($this->held()));

        $this->assertSame([$this->thread->id, $other->id], $found(null), 'once it is out');
    }

    #[Test]
    public function a_held_reply_offers_no_action_until_it_is_out(): void
    {
        AutomodRule::factory()->words(['cheap watches'])->create();
        $author = $this->member();
        $this->reply($author, '<p>cheap watches</p>');
        $this->reply($author, '<p>A reply that is fine</p>');

        $permissions = fn (User $viewer, bool $held) => collect(
            $this->actingAs($viewer)->get(route('forum.threads.show', [$this->board, $this->thread]))->viewData('page')['props']['posts']['data'],
        )->firstWhere('held', $held)['permissions'];

        $none = ['canReport' => false, 'canEdit' => false, 'canDelete' => false, 'canModerate' => false];

        $this->assertSame($none, $permissions($author, true), 'its author');
        $this->assertSame($none, $permissions($this->staff(), true), 'a moderator');

        $this->assertSame(['canReport' => false, 'canEdit' => true, 'canDelete' => true, 'canModerate' => false], $permissions($author, false), 'its author, once it is out');
        $this->assertSame(['canReport' => true, 'canEdit' => true, 'canDelete' => true, 'canModerate' => true], $permissions($this->staff(), false), 'a moderator, once it is out');
    }

    #[Test]
    public function a_held_reply_cannot_be_edited_deleted_reported_or_looked_up_in_the_history(): void
    {
        AutomodRule::factory()->words(['cheap watches'])->create();
        $author = $this->member();
        $this->reply($author, '<p>cheap watches</p>');
        $held = $this->held();
        $parameters = [$this->board, $this->thread, $held->id];

        $this->actingAs($author)->put(route('forum.posts.update', $parameters), ['body' => '<p>Something else</p>'])->assertNotFound();
        $this->actingAs($author)->delete(route('forum.posts.destroy', $parameters))->assertNotFound();

        $moderator = $this->staff();
        $this->actingAs($moderator)->post(route('forum.posts.report', $parameters), ['reason_category' => 'spam'])->assertNotFound();
        $this->actingAs($moderator)->get(route('forum.posts.history', $parameters))->assertNotFound();
        $this->actingAs($moderator)->delete(route('forum.posts.destroy', $parameters))->assertNotFound();

        $this->assertSame('<p>cheap watches</p>', $held->fresh()->body);
        $this->assertNotNull($this->held());
    }

    #[Test]
    public function a_held_reply_is_never_the_latest_post_the_api_shows(): void
    {
        AutomodRule::factory()->words(['cheap watches'])->create();
        $visible = ForumPost::create(['forum_thread_id' => $this->thread->id, 'user_id' => $this->member()->id, 'body' => '<p>Fine</p>']);
        $this->reply($this->member(), '<p>cheap watches</p>');
        Sanctum::actingAs($this->member(), ['*']);

        $this->getJson(route('api.v1.forum.threads.show', $this->thread))
            ->assertOk()
            ->assertJsonPath('data.latest_post.id', $visible->id);
    }

    // --- A thread -------------------------------------------------------------------------------------------------------------

    #[Test]
    public function a_thread_that_breaks_no_rule_is_out_at_once_and_pays_its_author(): void
    {
        $author = $this->member();

        $this->actingAs($author)->post(route('forum.threads.store', $this->board), ['title' => 'A fine thread', 'body' => '<p>All good</p>'])->assertRedirect();

        $thread = ForumThread::query()->where('title', 'A fine thread')->sole();
        $this->assertTrue($thread->is_published);
        $this->assertNull($thread->held_at);
        $this->assertSame(5, $author->fresh()->reputation_points);
        $this->assertDatabaseHas('forum_thread_reads', ['forum_thread_id' => $thread->id, 'user_id' => $author->id]);
    }

    #[Test]
    public function a_held_thread_is_unpublished_and_only_the_moderators_can_open_it(): void
    {
        AutomodRule::factory()->words(['free money'])->create();
        $author = $this->member();

        $this->actingAs($author)->post(route('forum.threads.store', $this->board), ['title' => 'Free money here', 'body' => '<p>Come and see</p>'])
            ->assertRedirect(route('forum.boards.show', $this->board))
            ->assertSessionHas('success', 'Your thread has been sent for review. It will appear when a moderator approves it.');

        $thread = ForumThread::query()->where('title', 'Free money here')->sole();
        $this->assertFalse($thread->is_published);
        $this->assertNotNull($thread->held_at);
        $this->assertSame(0, $author->fresh()->reputation_points);

        $this->actingAs($author)->get(route('forum.threads.show', [$this->board, $thread]))->assertNotFound();
        $this->actingAs($this->member())->get(route('forum.boards.show', $this->board))->assertDontSee('Free money here');
        $this->actingAs($this->staff())->get(route('forum.threads.show', [$this->board, $thread]))->assertOk();
    }

    #[Test]
    public function approving_a_held_thread_publishes_it_and_pays_the_author(): void
    {
        AutomodRule::factory()->words(['free money'])->create();
        $author = $this->member();
        $this->actingAs($author)->post(route('forum.threads.store', $this->board), ['title' => 'Free money here', 'body' => '<p>Come and see</p>']);
        $thread = ForumThread::query()->where('title', 'Free money here')->sole();

        $this->assertTrue(app(ForumReview::class)->approveThread($thread));

        $thread->refresh();
        $this->assertTrue($thread->is_published);
        $this->assertNull($thread->held_at);
        $this->assertNull($thread->held_reason);
        $this->assertSame(5, $author->fresh()->reputation_points);
        $this->actingAs($this->member())->get(route('forum.threads.show', [$this->board, $thread]))->assertOk();
        $this->assertFalse(app(ForumReview::class)->approveThread($thread), 'a second time finds nothing held');
    }

    #[Test]
    public function rejecting_a_held_thread_removes_it(): void
    {
        AutomodRule::factory()->words(['free money'])->create();
        $this->actingAs($this->member())->post(route('forum.threads.store', $this->board), ['title' => 'Free money here', 'body' => '<p>Come and see</p>']);
        $thread = ForumThread::query()->where('title', 'Free money here')->sole();

        $this->assertTrue(app(ForumReview::class)->rejectThread($thread));

        $this->assertModelMissing($thread);
        $this->assertFalse(app(ForumReview::class)->rejectThread($thread));
    }

    #[Test]
    public function publishing_a_held_thread_any_other_way_ends_the_hold_too(): void
    {
        AutomodRule::factory()->words(['free money'])->create();
        $this->actingAs($this->member())->post(route('forum.threads.store', $this->board), ['title' => 'Free money here', 'body' => '<p>Come and see</p>']);
        $thread = ForumThread::query()->where('title', 'Free money here')->sole();

        $this->actingAs($this->staff())->put(route('forum.threads.publish', [$this->board, $thread]))->assertRedirect();

        $this->assertTrue($thread->fresh()->is_published);
        $this->assertNull($thread->fresh()->held_at, 'a published thread is not held');
    }

    #[Test]
    public function a_thread_held_by_a_rule_that_is_then_deleted_keeps_why(): void
    {
        $rule = AutomodRule::factory()->words(['free money'])->create(['name' => 'Money talk']);
        $this->actingAs($this->member())->post(route('forum.threads.store', $this->board), ['title' => 'Free money here', 'body' => '<p>Come and see</p>']);

        $rule->delete();

        $thread = ForumThread::query()->where('title', 'Free money here')->sole();
        $this->assertNull($thread->held_by_rule_id);
        $this->assertSame('Money talk: contains "free money"', $thread->held_reason);
        $this->assertNotNull($thread->held_at, 'still waiting for a moderator');
    }

    // --- The API --------------------------------------------------------------------------------------------------------------

    #[Test]
    public function the_api_answers_202_for_a_held_reply_and_says_so(): void
    {
        AutomodRule::factory()->words(['cheap watches'])->create();
        Sanctum::actingAs($this->member(), ['*']);

        $this->postJson(route('api.v1.forum.posts.store', [$this->board, $this->thread]), ['body' => '<p>cheap watches</p>'])
            ->assertStatus(202)
            ->assertJsonPath('held', true)
            ->assertJsonPath('body', '<p>cheap watches</p>');

        $this->assertNotNull($this->held());
    }

    #[Test]
    public function the_api_answers_201_with_no_held_key_for_a_reply_that_is_out(): void
    {
        Sanctum::actingAs($this->member(), ['*']);

        $this->postJson(route('api.v1.forum.posts.store', [$this->board, $this->thread]), ['body' => '<p>A fine reply</p>'])
            ->assertCreated()
            ->assertJsonMissingPath('held');
    }

    #[Test]
    public function the_api_answers_202_for_a_held_thread_and_422_for_one_that_is_refused(): void
    {
        AutomodRule::factory()->words(['free money'])->create();
        AutomodRule::factory()->words(['crypto giveaway'])->rejecting()->create();
        Sanctum::actingAs($this->member(), ['*']);

        $this->postJson(route('api.v1.forum.threads.store', $this->board), ['title' => 'Free money', 'body' => '<p>See inside</p>'])
            ->assertStatus(202)
            ->assertJsonPath('thread.held', true)
            ->assertJsonPath('thread.board.name', 'The lounge')
            ->assertJsonPath('initial_post.body', '<p>See inside</p>');

        $this->actingAs($this->member())->getJson(route('api.v1.forum.threads.index'))->assertOk()->assertJsonMissing(['title' => 'Free money']);
        Sanctum::actingAs($this->member(), ['*']);

        $this->postJson(route('api.v1.forum.threads.store', $this->board), ['title' => 'Crypto giveaway', 'body' => '<p>See inside</p>'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body');

        $this->assertSame(1, ForumThread::query()->whereNotNull('held_at')->count());
    }

    // --- The trap -------------------------------------------------------------------------------------------------------------

    #[Test]
    public function a_form_with_the_hidden_field_filled_in_is_not_posted(): void
    {
        $author = $this->member();

        $this->actingAs($author)->post(route('forum.posts.store', [$this->board, $this->thread]), ['body' => '<p>Hello</p>', 'honeypot' => 'http://spam.example'])
            ->assertSessionHasErrors('honeypot');

        $this->actingAs($author)->post(route('forum.threads.store', $this->board), ['title' => 'Hello', 'body' => '<p>Hello</p>', 'honeypot' => 'x'])
            ->assertSessionHasErrors('honeypot');

        $this->actingAs($author)->post(route('forum.posts.store', [$this->board, $this->thread]), ['body' => '<p>Hello</p>', 'honeypot' => ['x']])
            ->assertSessionHasErrors('honeypot');

        $this->assertSame(0, ForumPost::query()->count());
        $this->assertSame(1, ForumThread::query()->count());
    }

    #[Test]
    public function an_empty_hidden_field_does_no_harm(): void
    {
        $this->actingAs($this->member())->post(route('forum.posts.store', [$this->board, $this->thread]), ['body' => '<p>Hello</p>', 'honeypot' => ''])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, ForumPost::query()->count());
    }

    // --- Where a held reply shows on the member's side --------------------------------------------------------------------------

    #[Test]
    public function a_held_reply_does_not_tick_the_onboarding_step_for_saying_hello(): void
    {
        AutomodRule::factory()->words(['cheap watches'])->create();
        $author = $this->member(['created_at' => now()]);

        $this->reply($author, '<p>cheap watches</p>');

        $this->actingAs($author)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('onboarding.steps', fn ($steps) => collect($steps)->firstWhere('key', 'community')['done'] === false));
    }
}
