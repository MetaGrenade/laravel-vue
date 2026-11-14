<?php

namespace Tests\Feature\Search;

use App\Models\Blog;
use App\Models\User;
use App\Support\Search\GlobalSearchService;
use App\Support\Text\PlainText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A blog post's body is stored as the sanitiser leaves it, HTML with its entities (`Don&#039;t`, `Tom &amp; Jerry`, `</p><p>`).
 * The database search read that: a search for *don't* found nothing in a post that says so, and *https*, *href*, *noopener* or
 * *blockquote* found every post with a link or a quote. It reads `body_text` now, the words of the body, kept by the model.
 */
class BlogBodySearchTest extends TestCase
{
    use RefreshDatabase;

    private const BODY = '<p>Don\'t panic</p><p>Tom &amp; Jerry say "hi"</p><p><a href="https://example.com/towel">the guide</a></p>';

    // --- What is kept ---------------------------------------------------------------------------------------

    #[Test]
    public function saving_a_post_keeps_the_words_of_its_body(): void
    {
        $blog = $this->blog(['body' => self::BODY]);

        $this->assertSame('Don\'t panic Tom & Jerry say "hi" the guide', Blog::query()->findOrFail($blog->id)->body_text);
        $this->assertStringContainsString('&amp;', (string) Blog::query()->findOrFail($blog->id)->body, 'the body itself is still the HTML that was stored');
    }

    #[Test]
    public function a_post_with_no_body_has_no_words(): void
    {
        $blog = $this->blog(['body' => '']);

        $this->assertSame('', Blog::query()->findOrFail($blog->id)->body_text);
    }

    #[Test]
    public function changing_the_body_changes_the_words_and_changing_anything_else_does_not(): void
    {
        $blog = $this->blog(['body' => self::BODY]);

        DB::table('blogs')->where('id', $blog->id)->update(['body_text' => 'kept as it was']);

        $blog = Blog::query()->findOrFail($blog->id);
        $blog->update(['title' => 'Another title', 'status' => 'draft']);
        $blog->increment('views');

        $this->assertSame('kept as it was', Blog::query()->findOrFail($blog->id)->body_text, 'a title, a status or a view is not a change of the words');

        $blog = Blog::query()->findOrFail($blog->id);
        $blog->update(['body' => '<p>Not what it was &lt;b&gt;</p><p>at all</p>']);

        $this->assertSame('Not what it was <b> at all', Blog::query()->findOrFail($blog->id)->body_text);
    }

    #[Test]
    public function the_words_are_not_sent_with_the_post(): void
    {
        $blog = $this->blog(['body' => self::BODY]);
        $fresh = Blog::query()->findOrFail($blog->id);

        $this->assertArrayNotHasKey('body_text', $fresh->toArray());
        $this->assertStringNotContainsString('body_text', $fresh->toJson());
        $this->assertNotNull($fresh->body_text, 'it is there for the search to read');
    }

    // --- What the search finds ------------------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function wordsOfThePost(): array
    {
        return [
            'an apostrophe' => ['don\'t'],
            'across two paragraphs' => ['panic tom'],
            'an ampersand' => ['Tom & Jerry'],
            'quotation marks' => ['"hi"'],
            'a phrase with them' => ['say "hi" the guide'],
            'the text of a link' => ['the guide'],
        ];
    }

    #[Test]
    #[DataProvider('wordsOfThePost')]
    public function the_search_finds_the_words_a_post_says(string $term): void
    {
        $post = $this->blog(['body' => self::BODY]);
        $this->blog(['body' => '<blockquote>Words that are quoted</blockquote>']);

        $this->assertSame([$post->id], $this->found($term));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function markupOfThePost(): array
    {
        return [
            'the scheme of a link' => ['https'],
            'the address of a link' => ['example.com'],
            'the attribute of a link' => ['href'],
            'what the sanitiser adds to a link' => ['noopener'],
            'a tag of a quote' => ['blockquote'],
            'a tag' => ['<p>'],
            'an entity for an ampersand' => ['&amp'],
            'an entity for an apostrophe' => ['039'],
        ];
    }

    #[Test]
    #[DataProvider('markupOfThePost')]
    public function the_search_does_not_find_the_markup_of_a_post(string $term): void
    {
        $this->blog(['body' => self::BODY]);
        $this->blog(['body' => '<blockquote>Words that are quoted</blockquote>']);

        $this->assertSame([], $this->found($term), 'it is not something the post says');
    }

    #[Test]
    public function a_post_is_still_found_by_its_title_and_its_excerpt(): void
    {
        $post = $this->blog(['title' => 'A towel guide', 'excerpt' => 'Short and sweet.', 'body' => '<p>Nothing to do with it</p>']);

        $this->assertSame([$post->id], $this->found('towel'));
        $this->assertSame([$post->id], $this->found('sweet'));
    }

    #[Test]
    public function a_match_in_the_words_of_the_body_ranks_above_one_in_the_name_of_the_author(): void
    {
        $inTheBody = $this->blog(['body' => '<p>Don\'t panic</p>', 'published_at' => now()->subDay()]);
        $byTheAuthor = $this->blog([
            'body' => '<p>Nothing to see</p>',
            'user_id' => User::factory()->create(['nickname' => 'Don\'t Panic'])->id,
            'published_at' => now(),
        ]);

        $this->assertSame([$inTheBody->id, $byTheAuthor->id], $this->found('don\'t'), 'the body weighs 3 and the author 2, and the later post does not come first for being later');
    }

    #[Test]
    public function the_match_in_the_body_is_marked_in_what_is_shown(): void
    {
        $this->blog(['body' => self::BODY]);

        $this->assertStringContainsString('<mark>Don&#039;t</mark> panic', $this->shown('don\'t')['highlight']['description']);
        $this->assertStringContainsString('<mark>Tom &amp; Jerry</mark> say &quot;hi&quot;', $this->shown('Tom & Jerry')['highlight']['description']);
        $this->assertStringContainsString('say <mark>&quot;hi&quot;</mark>', $this->shown('"hi"')['highlight']['description']);
    }

    #[Test]
    public function a_term_that_is_part_of_an_entity_is_not_marked_inside_it(): void
    {
        $this->blog(['body' => '<p>A camp &amp; a lamp</p>']);

        $this->assertSame('A c<mark>amp</mark> &amp; a l<mark>amp</mark>', $this->shown('amp')['highlight']['description']);
    }

    #[Test]
    public function a_term_is_marked_as_the_words_it_is_and_not_as_a_pattern(): void
    {
        $this->blog(['body' => '<p>Use C++ or a.b (not axb)</p>']);

        $this->assertSame('Use <mark>C++</mark> or a.b (not axb)', $this->shown('C++')['highlight']['description']);
        $this->assertSame('Use C++ or <mark>a.b</mark> (not axb)', $this->shown('a.b')['highlight']['description']);
        $this->assertSame('Use C++ or a.b <mark>(not</mark> axb)', $this->shown('(not')['highlight']['description']);
    }

    #[Test]
    public function what_is_shown_of_a_post_without_its_words_yet_is_read_from_its_html(): void
    {
        $post = $this->blog(['body' => self::BODY, 'excerpt' => '']);

        DB::table('blogs')->where('id', $post->id)->update(['body_text' => null]);

        $shown = app(GlobalSearchService::class)->presentBlog(Blog::query()->findOrFail($post->id), 'Tom & Jerry');

        $this->assertSame('Don\'t panic Tom & Jerry say "hi" the guide', $shown['description']);
        $this->assertStringContainsString('<mark>Tom &amp; Jerry</mark>', $shown['highlight']['description']);
    }

    // --- The command that writes around the model ------------------------------------------------------------

    #[Test]
    public function sanitising_the_stored_content_keeps_the_words_in_step(): void
    {
        $post = $this->blog(['body' => '<p>Before</p>']);

        DB::table('blogs')->where('id', $post->id)->update(['body' => '<p>After<script>alert(1)</script> &amp; more</p>', 'body_text' => 'stale']);

        $this->artisan('content:sanitize', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame('stale', Blog::query()->findOrFail($post->id)->body_text, 'a dry run changes nothing');

        $this->artisan('content:sanitize')->assertSuccessful();

        $fresh = Blog::query()->findOrFail($post->id);

        $this->assertStringNotContainsString('<script', (string) $fresh->body);
        $this->assertSame(PlainText::of($fresh->body), $fresh->body_text);
        $this->assertStringContainsString('& more', (string) $fresh->body_text);
    }

    // --- The migration ---------------------------------------------------------------------------------------

    #[Test]
    public function the_migration_fills_in_the_words_of_the_posts_a_site_already_has(): void
    {
        $migration = require database_path('migrations/2026_11_14_000100_add_body_text_to_blogs_table.php');
        $ids = [];

        // DDL commits the test's transaction on MySQL, so what this test writes is removed by hand and the schema is put back before it ends.
        try {
            $ids[] = $this->blog(['body' => self::BODY])->id;
            $ids[] = $this->blog(['body' => '<blockquote>Words that are quoted</blockquote><p>and more</p>'])->id;
            $ids[] = $this->blog(['body' => ''])->id;

            $migration->down();
            $this->assertFalse(Schema::hasColumn('blogs', 'body_text'), 'a site from before this change has no such column');

            $migration->up();
            $this->assertTrue(Schema::hasColumn('blogs', 'body_text'));

            $this->assertSame('Don\'t panic Tom & Jerry say "hi" the guide', DB::table('blogs')->where('id', $ids[0])->value('body_text'));
            $this->assertSame('Words that are quoted and more', DB::table('blogs')->where('id', $ids[1])->value('body_text'));
            $this->assertSame('', DB::table('blogs')->where('id', $ids[2])->value('body_text'));
            $this->assertStringContainsString('&amp;', (string) DB::table('blogs')->where('id', $ids[0])->value('body'), 'the body is not touched');

            DB::table('blogs')->where('id', $ids[1])->update(['body_text' => null]);
            $migration->up();
            $this->assertSame('Words that are quoted and more', DB::table('blogs')->where('id', $ids[1])->value('body_text'), 'running it again fills in what is not there');
        } finally {
            if (! Schema::hasColumn('blogs', 'body_text')) {
                $migration->up();
            }

            $authors = Blog::query()->whereKey($ids)->pluck('user_id')->all();

            Blog::query()->whereKey($ids)->delete();
            User::query()->whereKey($authors)->delete();
        }
    }

    // --- Helpers ----------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function blog(array $attributes = []): Blog
    {
        return Blog::factory()->published()->create($attributes + ['excerpt' => 'An excerpt.']);
    }

    /**
     * @return list<int>
     */
    private function found(string $term): array
    {
        return array_map(
            fn (array $item): int => $item['id'],
            app(GlobalSearchService::class)->search($term, ['types' => ['blogs']])['results']['blogs']['items'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function shown(string $term): array
    {
        $items = app(GlobalSearchService::class)->search($term, ['types' => ['blogs']])['results']['blogs']['items'];

        $this->assertCount(1, $items, 'one post says it');

        return $items[0];
    }
}
