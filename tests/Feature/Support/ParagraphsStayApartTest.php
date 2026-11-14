<?php

namespace Tests\Feature\Support;

use App\Models\Blog;
use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\ReputationEvent;
use App\Models\TebexPackage;
use App\Models\User;
use App\Notifications\ForumPostMentioned;
use App\Support\Forum\FollowedMembers;
use App\Support\Forum\ForumPublisher;
use App\Support\Legal\LegalPages;
use App\Support\Security\HtmlSanitizer;
use App\Support\Seo\Seo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithCommerce;
use Tests\Support\InteractsWithTebex;
use Tests\Support\ReadsDataExport;
use Tests\TestCase;

/**
 * Text made from HTML a member or an admin wrote. The editor puts no space between `</p><p>`, `</li><li>` or `<br>`, so text made with `strip_tags` alone
 * joins the last word of one block to the first word of the next. Seven places decoded the entities but still did that: a mention that starts a paragraph
 * was not a mention (`Thanks@Ada`), and a page description, a profile, a feed, the leaderboard, a Tebex package and the member export each read
 * `IntroFirst paragraph`. They use `App\Support\Text\PlainText` now, as the notices already did.
 */
class ParagraphsStayApartTest extends TestCase
{
    use InteractsWithCommerce;
    use InteractsWithTebex;
    use ReadsDataExport;
    use RefreshDatabase;

    // --- A mention is the start of a word ------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function bodiesThatMention(): array
    {
        return [
            'on its own in the first paragraph' => ['<p>Hello @Ada</p>'],
            'a paragraph that starts with it' => ['<p>Thanks for the help</p><p>@Ada can you look?</p>'],
            'the second of two list items' => ['<ul><li>Bea</li><li>@Ada</li></ul>'],
            'after a line break' => ['<p>Thanks<br>@Ada</p>'],
            'after a quote' => ['<blockquote>Quoted</blockquote><p>@Ada</p>'],
            'the link the editor makes' => ['<p>Thanks</p><p><a data-type="mention" href="https://example.test/members/1">@Ada</a> look</p>'],
        ];
    }

    #[Test]
    #[DataProvider('bodiesThatMention')]
    public function a_member_named_at_the_start_of_a_block_is_a_mention(string $html): void
    {
        $author = User::factory()->create();
        $ada = User::factory()->create(['nickname' => 'Ada']);

        $found = app(ForumPublisher::class)->resolveMentionedUsers($this->stored($html), $author->id);

        $this->assertSame([$ada->id], $found->pluck('id')->all());
    }

    #[Test]
    public function a_nickname_in_the_middle_of_a_post_and_after_an_entity_is_still_a_mention(): void
    {
        $author = User::factory()->create();
        $ada = User::factory()->create(['nickname' => 'Ada']);
        $bea = User::factory()->create(['nickname' => 'Bea']);

        $found = app(ForumPublisher::class)->resolveMentionedUsers($this->stored('<p>Don\'t forget @Ada &amp; @Bea and more</p>'), $author->id);

        $this->assertEqualsCanonicalizing([$ada->id, $bea->id], $found->pluck('id')->all());
    }

    #[Test]
    public function an_address_and_a_word_with_an_at_sign_inside_it_are_not_mentions(): void
    {
        $author = User::factory()->create();
        User::factory()->create(['nickname' => 'Ada']);

        $publisher = app(ForumPublisher::class);

        $this->assertCount(0, $publisher->resolveMentionedUsers($this->stored('<p>Write to me@Ada.example</p>'), $author->id));
        $this->assertCount(0, $publisher->resolveMentionedUsers($this->stored('<p>word</p><p>x@Ada</p>'), $author->id), 'an at sign that follows a letter is not the start of a name, in any block');
        $this->assertCount(0, $publisher->resolveMentionedUsers($this->stored('<p>a@@Ada</p>'), $author->id));
    }

    #[Test]
    public function a_reply_whose_second_paragraph_starts_with_a_mention_tells_the_member(): void
    {
        Notification::fake();

        [$board, $thread] = $this->forumContext();
        $replier = User::factory()->create();
        $ada = User::factory()->create(['nickname' => 'Ada']);

        $this->actingAs($replier)->post(route('forum.posts.store', [$board, $thread]), [
            'body' => '<p>Thanks for the help</p><p>@Ada can you look at this?</p>',
        ])->assertRedirect();

        $post = ForumPost::query()->where('forum_thread_id', $thread->id)->latest('id')->firstOrFail();

        $this->assertDatabaseHas('forum_post_mentions', ['forum_post_id' => $post->id, 'mentioned_user_id' => $ada->id]);
        Notification::assertSentTo($ada, ForumPostMentioned::class);
    }

    // --- A description is made of the words ----------------------------------------------------------------

    #[Test]
    public function a_page_description_made_from_html_keeps_its_blocks_apart(): void
    {
        $meta = app(Seo::class)
            ->title('<b>A</b><br>title')
            ->description('<h2>Intro</h2><p>First &amp; second</p><p>Don&#039;t stop</p><ul><li>one</li><li>two</li></ul>')
            ->toArray();

        $this->assertSame('Intro First & second Don\'t stop one two', $meta['meta']['description']['content']);
        $this->assertSame('A title', $meta['title']);
    }

    #[Test]
    public function a_blog_post_with_no_excerpt_has_a_description_of_its_first_words(): void
    {
        Blog::factory()->published()->create([
            'slug' => 'no-excerpt',
            'excerpt' => null,
            'body' => '<h2>Intro</h2><p>First paragraph</p><p>Second paragraph</p>',
        ]);

        $this->get(route('blogs.view', ['slug' => 'no-excerpt']))
            ->assertOk()
            ->assertSee('<meta name="description" content="Intro First paragraph Second paragraph"', false)
            ->assertSee('<meta property="og:description" content="Intro First paragraph Second paragraph"', false);
    }

    #[Test]
    public function the_description_of_a_legal_page_keeps_its_blocks_apart(): void
    {
        app(LegalPages::class)->save([
            'terms' => '<h2>Terms of use</h2><p>By using this site you agree to them.</p><p>That\'s all.</p>',
        ]);

        $this->assertSame('Terms of use By using this site you agree to them. That\'s all.', app(LegalPages::class)->description('terms'));
    }

    #[Test]
    public function the_description_of_a_tebex_package_keeps_its_blocks_apart(): void
    {
        $this->setUpCommerce();
        $this->setUpTebex();
        $this->sellThroughTebex();

        $package = TebexPackage::factory()->priced('10.00')->create([
            'name' => 'VIP',
            'description' => '<h2>Perks</h2><p>Priority queue</p><ul><li>Kit</li><li>Cape</li></ul>',
        ]);

        $this->get(route('shop.packages'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('packages.data.0.excerpt', 'Perks Priority queue Kit Cape'));

        $this->get(route('shop.packages.show', $package))
            ->assertOk()
            ->assertSee('<meta name="description" content="Perks Priority queue Kit Cape"', false);
    }

    // --- An excerpt is made of the words -------------------------------------------------------------------

    #[Test]
    public function a_reply_on_a_profile_keeps_its_paragraphs_apart(): void
    {
        [, $thread] = $this->forumContext();
        $member = User::factory()->create(['nickname' => 'Ada', 'profile_bio' => "Writes notes.\nLoves <b>engines</b>"]);

        $this->reply($thread, $member, '<p>First paragraph</p><p>Don&#039;t stop</p>');

        $this->get(route('members.show', $member))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('member.activity.forum.replies.0.excerpt', 'First paragraph Don\'t stop')
                ->where('member.bio', "Writes notes.\nLoves engines"));
    }

    #[Test]
    public function a_bio_in_the_directory_is_the_words_of_it(): void
    {
        User::factory()->create(['nickname' => 'Ada', 'profile_bio' => "Writes notes.\n\nLoves engines"]);

        $this->get(route('members.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('members.data.0.bio', 'Writes notes. Loves engines'));
    }

    #[Test]
    public function the_feed_of_followed_members_keeps_the_paragraphs_of_a_post_apart(): void
    {
        [, $thread] = $this->forumContext();
        $author = User::factory()->create(['nickname' => 'Ada']);
        $viewer = User::factory()->create();

        $this->reply($thread, $author, '<p>First paragraph</p><p>Don&#039;t stop</p>');
        app(FollowedMembers::class)->follow($viewer, $author);

        $this->actingAs($viewer)
            ->get(route('forum.following'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('posts.data.0.excerpt', 'First paragraph Don\'t stop'));
    }

    #[Test]
    public function the_leaderboard_activity_keeps_the_paragraphs_of_a_post_apart(): void
    {
        [, $thread] = $this->forumContext();
        $author = User::factory()->create(['nickname' => 'Ada']);
        $post = $this->reply($thread, $author, '<p>First paragraph</p><p>Don&#039;t stop</p>');

        ReputationEvent::create([
            'user_id' => $author->id,
            'event' => 'forum_post_created',
            'points' => 5,
            'source_type' => $post->getMorphClass(),
            'source_id' => $post->id,
        ]);

        $this->get(route('leaderboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('activity.0.post.excerpt', 'First paragraph Don\'t stop'));
    }

    #[Test]
    public function a_long_post_is_cut_at_its_limit_on_a_profile_in_the_feed_and_on_the_leaderboard(): void
    {
        [, $thread] = $this->forumContext();
        $author = User::factory()->create(['nickname' => 'Ada', 'profile_bio' => str_repeat('word ', 100)]);
        $viewer = User::factory()->create();
        $post = $this->reply($thread, $author, '<p>'.str_repeat('word ', 60).'</p><p>'.str_repeat('more ', 60).'</p>');
        $expected = Str::limit(trim(str_repeat('word ', 60).str_repeat('more ', 60)), 180);

        ReputationEvent::create(['user_id' => $author->id, 'event' => 'forum_post_created', 'points' => 5, 'source_type' => $post->getMorphClass(), 'source_id' => $post->id]);
        app(FollowedMembers::class)->follow($viewer, $author);

        $this->assertStringEndsWith('...', $expected, 'a cut excerpt says so');

        $this->get(route('members.show', $author))->assertInertia(fn (Assert $page) => $page->where('member.activity.forum.replies.0.excerpt', $expected));
        $this->actingAs($viewer)->get(route('forum.following'))->assertInertia(fn (Assert $page) => $page->where('posts.data.0.excerpt', $expected));
        $this->get(route('leaderboard'))->assertInertia(fn (Assert $page) => $page->where('activity.0.post.excerpt', $expected));
        $this->get(route('members.index'))->assertInertia(fn (Assert $page) => $page->where(
            'members.data',
            fn (mixed $members): bool => collect($members)->firstWhere('nickname', 'Ada')['bio'] === Str::limit(trim(str_repeat('word ', 100)), 160),
        ));
    }

    #[Test]
    public function a_post_with_nothing_to_read_has_no_excerpt(): void
    {
        [, $thread] = $this->forumContext();
        $author = User::factory()->create(['nickname' => 'Ada']);
        $viewer = User::factory()->create();
        $post = $this->reply($thread, $author, '<p>&nbsp;</p><p> </p>');

        ReputationEvent::create(['user_id' => $author->id, 'event' => 'forum_post_created', 'points' => 5, 'source_type' => $post->getMorphClass(), 'source_id' => $post->id]);
        app(FollowedMembers::class)->follow($viewer, $author);

        $this->get(route('members.show', $author))->assertInertia(fn (Assert $page) => $page->where('member.activity.forum.replies.0.excerpt', null));
        $this->actingAs($viewer)->get(route('forum.following'))->assertInertia(fn (Assert $page) => $page->where('posts.data.0.excerpt', null));
        $this->get(route('leaderboard'))->assertInertia(fn (Assert $page) => $page->where('activity.0.post.excerpt', null));
    }

    #[Test]
    public function a_long_description_is_cut_at_a_word_and_a_long_summary_at_its_limit(): void
    {
        $long = str_repeat('words ', 100);

        $this->assertSame(
            Str::limit(trim($long), 160, '…', preserveWords: true),
            app(Seo::class)->description('<p>'.$long.'</p>')->toArray()['meta']['description']['content'],
        );

        app(LegalPages::class)->save(['terms' => '<p>'.$long.'</p><p>'.$long.'</p>']);
        $this->assertSame(Str::limit(trim($long.$long), 160), app(LegalPages::class)->description('terms'));

        [, $thread] = $this->forumContext();
        $author = User::factory()->create();
        $post = $this->reply($thread, $author, '<p>'.$long.'</p>');

        $row = collect(explode("\n", $this->dataExportOf($author)['csv']))
            ->map(fn (string $line): array => str_getcsv($line, escape: ''))
            ->first(fn (array $cells): bool => ($cells[0] ?? null) === 'forum_post' && ($cells[1] ?? null) === (string) $post->id);

        $this->assertSame(Str::limit(trim($long), 120), $row[2] ?? null);
    }

    #[Test]
    public function the_summary_of_a_post_in_the_member_export_is_its_words(): void
    {
        [, $thread] = $this->forumContext();
        $author = User::factory()->create();
        $post = $this->reply($thread, $author, '<p>First paragraph</p><p>Don&#039;t stop &amp; read &lt;b&gt;</p>');

        $export = $this->dataExportOf($author);
        $export['decoded'] = json_decode($export['json'], true, flags: JSON_THROW_ON_ERROR);
        $row = collect(explode("\n", $export['csv']))
            ->map(fn (string $line): array => str_getcsv($line, escape: ''))
            ->first(fn (array $cells): bool => ($cells[0] ?? null) === 'forum_post' && ($cells[1] ?? null) === (string) $post->id);

        $this->assertNotNull($row, 'the post has a row');
        $this->assertSame('First paragraph Don\'t stop & read <b>', $row[2]);

        $strings = [];
        array_walk_recursive($export['decoded'], function (mixed $value) use (&$strings): void {
            if (is_string($value)) {
                $strings[] = $value;
            }
        });

        $this->assertContains((string) $post->fresh()->body, $strings, 'export.json keeps the post as it was stored, and is not changed by this');
    }

    // --- Helpers ----------------------------------------------------------------------------------------------

    private function stored(string $html): string
    {
        return app(HtmlSanitizer::class)->forum($html);
    }

    /**
     * @return array{ForumBoard, ForumThread}
     */
    private function forumContext(): array
    {
        $category = ForumCategory::create(['title' => 'General', 'slug' => 'general', 'description' => 'General', 'position' => 1]);
        $board = ForumBoard::create(['forum_category_id' => $category->id, 'title' => 'Announcements', 'slug' => 'announcements', 'description' => 'Announcements', 'position' => 1]);
        $starter = User::factory()->create();

        $thread = ForumThread::create([
            'forum_board_id' => $board->id,
            'user_id' => $starter->id,
            'title' => 'Thread Title',
            'slug' => Str::slug('Thread Title'),
            'excerpt' => null,
            'is_locked' => false,
            'is_pinned' => false,
            'is_published' => true,
            'views' => 0,
            'last_posted_at' => now(),
            'last_post_user_id' => $starter->id,
        ]);

        ForumPost::create(['forum_thread_id' => $thread->id, 'user_id' => $starter->id, 'body' => '<p>Thread opener</p>']);

        return [$board, $thread];
    }

    private function reply(ForumThread $thread, User $author, string $html): ForumPost
    {
        return ForumPost::create(['forum_thread_id' => $thread->id, 'user_id' => $author->id, 'body' => $html]);
    }
}
