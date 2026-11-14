<?php

namespace Tests\Feature\Forum;

use App\Events\ForumPostCreated;
use App\Models\Blog;
use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumPostReport;
use App\Models\ForumThread;
use App\Models\User;
use App\Notifications\ForumFollowedMemberPosted;
use App\Notifications\ForumPostMentioned;
use App\Notifications\ForumThreadUpdated;
use App\Support\Search\GlobalSearchService;
use App\Support\Search\SearchText;
use App\Support\Text\PlainText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * What a member writes is stored as the sanitiser makes it (`Don't` is `Don&#039;t`, `Q&A` is `Q&amp;A`, a paragraph ends with `</p>`),
 * and it is read back as text in a mention email, the bell, a search result, an excerpt, a quoted reply. Each of them has to give the words
 * the member wrote, and not the entities, and not two paragraphs run into one word.
 *
 * Every body here is the one a member would have typed; the model's cast makes it what is stored.
 */
class StoredTextAsTextTest extends TestCase
{
    use RefreshDatabase;

    /** What was typed: an apostrophe, quotation marks, an ampersand, a less-than sign, then two more blocks. */
    private const TYPED = "<p>Don't forget: Q&A with R&D, say \"hi\" & 5 &lt; 6</p><p>Second paragraph</p><ul><li>one</li><li>two</li></ul>";

    /** What a reader should get of it, on one line. */
    private const WORDS = 'Don\'t forget: Q&A with R&D, say "hi" & 5 < 6 Second paragraph one two';

    private ?ForumBoard $board = null;

    private int $made = 0;

    private function board(): ForumBoard
    {
        if ($this->board === null) {
            $category = ForumCategory::create(['title' => 'General', 'slug' => 'general', 'description' => 'General talk', 'position' => 1]);

            $this->board = ForumBoard::create([
                'forum_category_id' => $category->id,
                'title' => 'Announcements',
                'slug' => 'announcements',
                'description' => 'News',
                'position' => 1,
            ]);
        }

        return $this->board;
    }

    private function thread(string $excerpt = 'The start of it', ?User $author = null): ForumThread
    {
        $number = ++$this->made;
        $author ??= User::factory()->create();

        return ForumThread::create([
            'forum_board_id' => $this->board()->id,
            'user_id' => $author->id,
            'title' => 'A thread '.$number,
            'slug' => 'a-thread-'.$number,
            'excerpt' => $excerpt,
            'is_locked' => false,
            'is_pinned' => false,
            'is_published' => true,
            'views' => 0,
            'last_posted_at' => now(),
            'last_post_user_id' => $author->id,
        ]);
    }

    private function reply(ForumThread $thread, string $body = self::TYPED, ?User $author = null): ForumPost
    {
        return ForumPost::create([
            'forum_thread_id' => $thread->id,
            'user_id' => ($author ?? User::factory()->create())->id,
            'body' => $body,
        ]);
    }

    #[Test]
    public function the_sanitiser_does_store_the_entities_this_is_about(): void
    {
        $post = $this->reply($this->thread());

        $this->assertStringContainsString('Don&#039;t', $post->body);
        $this->assertStringContainsString('Q&amp;A', $post->body);
        $this->assertStringNotContainsString("Don't", $post->body);
    }

    // --- Notifications ---------------------------------------------------------------------------------

    #[Test]
    public function a_mention_notice_carries_the_words_and_not_the_entities(): void
    {
        $thread = $this->thread();
        $notice = new ForumPostMentioned($thread, $this->reply($thread));
        $member = User::factory()->create();

        $this->assertSame(PlainText::excerpt(self::TYPED, 140), $notice->toArray($member)['excerpt']);
        $this->assertSame(self::WORDS, $notice->toArray($member)['excerpt']);
        $this->assertSame(self::WORDS, $notice->toMail($member)->introLines[1]);
    }

    #[Test]
    public function a_mention_email_shows_each_character_once_escaped_and_not_twice(): void
    {
        $thread = $this->thread();
        $member = User::factory()->create();

        $html = (string) (new ForumPostMentioned($thread, $this->reply($thread)))->toMail($member)->render();

        // The mail template escapes what it is given, once: an ampersand is &amp; in the HTML, which a reader sees as an ampersand.
        $this->assertStringContainsString('Don\'t forget: Q&amp;A with R&amp;D, say "hi" &amp; 5 &lt; 6 Second paragraph one two', $html);
        $this->assertStringNotContainsString('&amp;#', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
        $this->assertStringNotContainsString('&amp;lt;', $html);
    }

    #[Test]
    public function a_thread_update_and_a_followed_member_notice_carry_the_words_too(): void
    {
        $thread = $this->thread();
        $post = $this->reply($thread);
        $member = User::factory()->create();
        $author = User::factory()->create();

        $updated = new ForumThreadUpdated($thread, $post);
        $followed = new ForumFollowedMemberPosted($thread, $post, $author);

        $this->assertSame(self::WORDS, $updated->toArray($member)['excerpt']);
        $this->assertSame(self::WORDS, $updated->toMail($member)->introLines[1]);
        $this->assertSame(self::WORDS, $followed->toArray($member)['excerpt']);
        $this->assertSame(self::WORDS, $followed->toMail($member)->introLines[1]);
    }

    #[Test]
    public function a_long_post_is_cut_by_what_a_reader_sees_and_not_by_the_entities(): void
    {
        $thread = $this->thread();
        // 40 apostrophes: 200 characters to a reader, a good deal more in what is stored.
        $post = $this->reply($thread, '<p>'.str_repeat("It's ", 40).'</p>');

        $excerpt = (new ForumPostMentioned($thread, $post))->toArray(User::factory()->create())['excerpt'];

        $this->assertStringStartsWith("It's It's It's", $excerpt);
        $this->assertStringEndsWith('...', $excerpt);
        $this->assertGreaterThanOrEqual(139, mb_strlen($excerpt) - 3, 'the limit is in characters a reader counts');
        $this->assertLessThanOrEqual(140, mb_strlen($excerpt) - 3);
        $this->assertStringNotContainsString('&#', $excerpt);
    }

    #[Test]
    public function the_live_post_notice_carries_the_words(): void
    {
        $thread = $this->thread();
        $event = new ForumPostCreated($thread, $this->reply($thread));

        $this->assertSame(self::WORDS, $event->broadcastWith()['post']['excerpt']);
    }

    // --- A quoted reply --------------------------------------------------------------------------------

    #[Test]
    public function a_quote_holds_the_words_that_were_written_and_each_block_is_a_paragraph(): void
    {
        $thread = $this->thread();
        $this->reply($thread);
        $page = $this->actingAs(User::factory()->create())
            ->get(route('forum.threads.show', [$this->board(), $thread]))
            ->assertOk()
            ->viewData('page');

        $quote = $page['props']['posts']['data'][0]['quote_html'];

        $this->assertSame(
            '<blockquote><p>Don&#039;t forget: Q&amp;A with R&amp;D, say &quot;hi&quot; &amp; 5 &lt; 6</p><p>Second paragraph</p><p>one</p><p>two</p></blockquote><p></p>',
            $quote,
        );
    }

    #[Test]
    public function a_quote_keeps_a_list_a_code_block_and_a_nested_quote_apart(): void
    {
        $thread = $this->thread();
        $this->reply($thread, '<p>Intro</p><ol><li>first</li><li>second</li></ol><pre>code line</pre>after code<blockquote>said before</blockquote>and then');

        $quote = $this->actingAs(User::factory()->create())
            ->get(route('forum.threads.show', [$this->board(), $thread]))
            ->viewData('page')['props']['posts']['data'][0]['quote_html'];

        $this->assertSame(
            '<blockquote><p>Intro</p><p>first</p><p>second</p><p>code line</p><p>after code</p><p>said before</p><p>and then</p></blockquote><p></p>',
            $quote,
        );
    }

    #[Test]
    public function a_reply_that_quotes_a_post_reads_as_the_words_after_it_is_stored(): void
    {
        $thread = $this->thread();
        $this->reply($thread);
        $replier = User::factory()->create();

        $quote = $this->actingAs($replier)
            ->get(route('forum.threads.show', [$this->board(), $thread]))
            ->viewData('page')['props']['posts']['data'][0]['quote_html'];

        $this->actingAs($replier)
            ->post(route('forum.posts.store', [$this->board(), $thread]), ['body' => $quote.'<p>Agreed.</p>'])
            ->assertRedirect();

        $reply = ForumPost::query()->where('user_id', $replier->id)->sole();
        $words = PlainText::of($reply->body);

        $this->assertSame(self::WORDS.' Agreed.', $words);
        $this->assertStringNotContainsString('&amp;#', $reply->body, 'an entity is not escaped a second time on its way into the quote');
    }

    // --- An excerpt kept with a thread -------------------------------------------------------------------

    #[Test]
    public function a_new_thread_keeps_an_excerpt_of_the_words_it_began_with(): void
    {
        $author = User::factory()->create();

        $this->actingAs($author)
            ->post(route('forum.threads.store', $this->board()), ['title' => 'Quotes and ampersands', 'body' => self::TYPED])
            ->assertRedirect();

        $thread = ForumThread::query()->where('title', 'Quotes and ampersands')->sole();

        $this->assertSame(self::WORDS, $thread->excerpt);
    }

    #[Test]
    public function the_excerpt_of_a_long_opening_post_is_cut_at_160_characters(): void
    {
        $typed = str_repeat('word ', 80);

        $this->actingAs(User::factory()->create())
            ->post(route('forum.threads.store', $this->board()), ['title' => 'A long one', 'body' => '<p>'.$typed.'</p>'])
            ->assertRedirect();

        $this->assertSame(rtrim(substr($typed, 0, 160)).'...', ForumThread::query()->where('title', 'A long one')->sole()->excerpt);
    }

    #[Test]
    public function the_forum_feed_gives_the_excerpt_as_text_and_a_typed_tag_stays_text(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('forum.threads.store', $this->board()), ['title' => 'Markup in a feed', 'body' => '<p>Use &lt;b&gt;bold&lt;/b&gt; &amp; more</p>'])
            ->assertRedirect();

        $xml = simplexml_load_string($this->get(route('forum.feed'))->assertOk()->getContent());
        $this->assertNotFalse($xml);

        $summary = $xml->entry[0]->summary;

        // type="html" would have a feed reader read the typed <b> as markup and show it bold.
        $this->assertSame('text', (string) $summary['type']);
        $this->assertSame('Use <b>bold</b> & more', trim((string) $summary));
    }

    // --- Staff area and search -----------------------------------------------------------------------------

    #[Test]
    public function a_reported_post_is_previewed_in_the_staff_area_as_the_words(): void
    {
        $role = Role::findOrCreate('moderator');
        $role->givePermissionTo(Permission::findOrCreate('forums.acp.view'));
        $moderator = User::factory()->create();
        $moderator->assignRole($role);

        $post = $this->reply($this->thread());
        (new ForumPostReport)->forceFill([
            'forum_post_id' => $post->id,
            'reporter_id' => User::factory()->create()->id,
            'reason_category' => 'spam',
            'reason' => 'Looks like spam.',
            'status' => ForumPostReport::STATUS_PENDING,
        ])->save();

        $this->actingAs($moderator)
            ->get(route('acp.forums.reports.index', ['type' => 'post']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('reports.data.0.post.body_preview', self::WORDS));
    }

    #[Test]
    public function the_words_of_a_post_are_what_the_search_index_holds(): void
    {
        $this->assertSame(self::WORDS, SearchText::plain($this->reply($this->thread())->body));

        // So that a search for "Don't" finds it, and a search for "amp" or "039" does not.
        $this->assertSame('Don\'t panic & run', $this->thread('Don&#039;t panic &amp; run')->toSearchableArray()['excerpt']);
    }

    #[Test]
    public function the_replies_a_thread_is_searched_by_are_the_words(): void
    {
        $thread = $this->thread();
        $this->reply($thread);

        $this->assertSame(self::WORDS, $thread->visibleReplyText());
        $this->assertSame(self::WORDS, $thread->toSearchableArray()['replies']);
    }

    #[Test]
    public function a_search_result_for_a_blog_shows_the_words_and_marks_the_term_once_escaped(): void
    {
        $blog = Blog::factory()->create(['title' => 'Rock & roll', 'excerpt' => null, 'body' => self::TYPED]);

        $result = app(GlobalSearchService::class)->presentBlog($blog, 'forget');

        $this->assertSame(self::WORDS, $result['description']);
        // Shown as HTML in the results page, so a character that means something in HTML is escaped, once.
        $this->assertSame(
            'Don&#039;t <mark>forget</mark>: Q&amp;A with R&amp;D, say &quot;hi&quot; &amp; 5 &lt; 6 Second paragraph one two',
            $result['highlight']['description'],
        );
        $this->assertSame('Rock &amp; <mark>roll</mark>', app(GlobalSearchService::class)->presentBlog($blog, 'roll')['highlight']['title']);
    }

    #[Test]
    public function a_search_result_shows_what_a_member_typed_as_markup_and_does_not_strip_it(): void
    {
        $blog = Blog::factory()->create(['title' => 'Markup', 'excerpt' => null, 'body' => '<p>Use &lt;b&gt;bold&lt;/b&gt; for emphasis</p>']);

        $result = app(GlobalSearchService::class)->presentBlog($blog, 'bold');

        $this->assertSame('Use <b>bold</b> for emphasis', $result['description']);
        $this->assertSame('Use &lt;b&gt;<mark>bold</mark>&lt;/b&gt; for emphasis', $result['highlight']['description']);
    }

    #[Test]
    public function a_search_result_for_a_blog_reads_its_headings_and_tables_as_separate_words(): void
    {
        $blog = Blog::factory()->create(['title' => 'Layout', 'excerpt' => null, 'body' => '<h2>Heading</h2><p>Text</p><table><tr><td>cell a</td><td>cell b</td></tr></table>']);

        $this->assertSame('Heading Text cell a cell b', app(GlobalSearchService::class)->presentBlog($blog, 'zzz')['description']);
    }

    // --- The thread page's own data for search engines ------------------------------------------------------

    #[Test]
    public function the_structured_data_of_a_thread_page_holds_the_words(): void
    {
        $thread = $this->thread();
        $this->reply($thread);

        $html = $this->get(route('forum.threads.show', [$this->board(), $thread]))->assertOk()->getContent();

        $this->assertSame(1, preg_match('~<script type="application/ld\+json"[^>]*>(.*?)</script>~s', (string) $html, $found), 'the page has structured data');

        $data = json_decode($found[1], true);

        $this->assertSame(self::WORDS, $data['text']);
    }

    #[Test]
    public function the_structured_data_of_a_thread_page_holds_the_words_of_the_answer_too(): void
    {
        $thread = $this->thread();
        $this->reply($thread);
        $answer = $this->reply($thread, '<p>It\'s &amp; fixed</p><p>Done</p>');
        $thread->forceFill(['solved_post_id' => $answer->id])->save();

        $html = $this->get(route('forum.threads.show', [$this->board(), $thread]))->assertOk()->getContent();

        $this->assertSame(1, preg_match('~<script type="application/ld\+json"[^>]*>(.*?)</script>~s', (string) $html, $found));
        $this->assertSame('It\'s & fixed Done', json_decode($found[1], true)['acceptedAnswer']['text']);
    }

    // --- What is already stored ------------------------------------------------------------------------------

    #[Test]
    public function the_excerpts_of_threads_made_before_are_made_right_by_the_migration(): void
    {
        $withEntities = $this->thread('Don&#039;t forget: Q&amp;A with R&amp;D, say &#34;hi&#34; &amp; 5 &lt; 6Second paragraph &apos;x&apos;');
        $plain = $this->thread('Nothing to decode here');
        $typedAnAmpersand = $this->thread('Fish & chips');
        // Has an & and a ; but nothing that is an entity: found by the query, nothing to write.
        $notAnEntity = $this->thread('R&D; then Q&A');
        $none = $this->thread();
        DB::table('forum_threads')->where('id', $none->id)->update(['excerpt' => null]);

        $migration = require database_path('migrations/2026_11_14_000000_decode_forum_thread_excerpts.php');

        $writes = 0;
        DB::listen(function ($query) use (&$writes) {
            if (str_starts_with($query->sql, 'update "forum_threads"') || str_starts_with($query->sql, 'update `forum_threads`')) {
                $writes++;
            }
        });

        $migration->up();

        $this->assertSame(1, $writes, 'only the excerpt that had entities in it is written');
        $this->assertSame('R&D; then Q&A', $notAnEntity->fresh()->excerpt);
        $this->assertSame('Don\'t forget: Q&A with R&D, say "hi" & 5 < 6Second paragraph \'x\'', $withEntities->fresh()->excerpt);
        $this->assertSame('Nothing to decode here', $plain->fresh()->excerpt);
        $this->assertSame('Fish & chips', $typedAnAmpersand->fresh()->excerpt);
        $this->assertNull($none->fresh()->excerpt);

        // Run again, it changes nothing.
        $migration->up();

        $this->assertSame(1, $writes);
        $this->assertSame('Don\'t forget: Q&A with R&D, say "hi" & 5 < 6Second paragraph \'x\'', $withEntities->fresh()->excerpt);
    }
}
