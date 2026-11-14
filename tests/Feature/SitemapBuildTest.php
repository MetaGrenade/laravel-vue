<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumThread;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * How `/sitemap.xml` is built when the site is big (docs/seo.md, "The sitemap and robots.txt"; docs/performance.md).
 *
 * It read the most `max_urls` rows of every kind as models, made an entry for each and cut the list to `max_urls` at the end, so a
 * site past the cap read three times what it published: 155,000 models at once, 128 MB and 2.5 to 8 s for 45,000 entries. Now there is
 * one budget for the file, and each kind is read as plain rows and only for the room that is left. The text must not change.
 */
class SitemapBuildTest extends TestCase
{
    use RefreshDatabase;

    private int $made = 0;

    private ?User $author = null;

    public function test_every_kind_is_listed_in_the_order_of_the_file_and_what_is_not_public_is_left_out(): void
    {
        [$blogs, $board, $threads, $products, $members] = $this->site();

        $expected = [
            route('home'),
            route('members.index'),
            route('pricing'),
            route('blogs.index'),
            route('blogs.view', ['slug' => $blogs['newest']]),
            route('blogs.view', ['slug' => $blogs['older']]),
            route('forum.index'),
            route('leaderboard'),
            route('forum.boards.show', ['board' => $board->slug]),
            route('forum.threads.show', ['board' => $board->slug, 'thread' => $threads['latest']]),
            route('forum.threads.show', ['board' => $board->slug, 'thread' => $threads['quiet']]),
            route('support'),
            route('shop.index'),
            route('shop.products.show', ['product' => $products['listed']]),
            ...array_map(fn (int $id): string => route('members.show', ['user' => $id]), $members['listed']),
        ];

        $xml = $this->sitemap();

        $this->assertSame($expected, $this->locations($xml));
        $this->assertStringNotContainsString($blogs['draft'], $xml);
        $this->assertStringNotContainsString($threads['hidden'], $xml);
        $this->assertStringNotContainsString($products['off'], $xml);
        $this->assertStringNotContainsString('/members/'.$members['banned']->id.'<', $xml);
        $this->assertGreaterThanOrEqual(3, count($members['listed']));
        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n  <url>", $xml);
        $this->assertStringEndsWith("</url>\n</urlset>\n", $xml);
    }

    public function test_an_entry_carries_its_time_and_priority_as_before(): void
    {
        [$blogs, $board, $threads, $products, $members] = $this->site();

        $xml = $this->sitemap();

        $this->assertStringContainsString(
            '  <url><loc>'.e(route('shop.products.show', ['product' => $products['listed']])).'</loc><lastmod>2026-04-04T00:00:00+00:00</lastmod><priority>0.6</priority></url>',
            $xml,
        );
        $this->assertStringContainsString(
            '  <url><loc>'.e(route('members.show', ['user' => $members['listed'][0]])).'</loc><lastmod>2026-06-01T00:00:00+00:00</lastmod><priority>0.4</priority></url>',
            $xml,
        );

        $this->assertStringContainsString(
            '  <url><loc>'.e(route('blogs.view', ['slug' => $blogs['newest']])).'</loc><lastmod>2026-02-02T00:00:00+00:00</lastmod><priority>0.7</priority></url>',
            $xml,
        );
        $this->assertStringContainsString(
            '  <url><loc>'.e(route('forum.threads.show', ['board' => $board->slug, 'thread' => $threads['latest']])).'</loc><lastmod>2026-03-03T00:00:00+00:00</lastmod><priority>0.5</priority></url>',
            $xml,
        );
        $this->assertStringContainsString('  <url><loc>'.e(route('home')).'</loc><priority>1.0</priority></url>', $xml);
    }

    public function test_a_full_sitemap_is_cut_from_the_end_and_each_kind_is_asked_for_only_the_room_left(): void
    {
        // The site makes 14 entries before its members: home, the directory, pricing, the blog's page, 2 posts, the forum's page, the
        // leaderboard, a board, 2 threads, support, the shop's page and a product.
        [, , , , $members] = $this->site();
        $this->assertCount(14 + count($members['listed']), $this->locations($this->sitemap()));

        // Four fixed entries before the first post: room for one post, and nothing after it is read.
        config(['seo.sitemap.max_urls' => 5]);
        $queries = $this->queriesDuring(fn (): string => $this->sitemap());
        $locations = $this->locations($this->sitemap());

        $this->assertCount(5, $locations);
        $this->assertSame(route('blogs.view', ['slug' => 'newest-post']), $locations[4]);
        $this->assertSame('limit 1', $this->readOf('blogs', $queries));
        $this->assertSame([], $this->readsOf('forum_threads', $queries));
        $this->assertSame([], $this->readsOf('products', $queries));
        $this->assertSame([], $this->readsOf('users', $queries));
    }

    public function test_nothing_is_read_for_a_sitemap_that_the_fixed_entries_fill(): void
    {
        $this->site();

        // Home, the directory, pricing and the blog's page fill it: not a post, thread, product or member is read.
        config(['seo.sitemap.max_urls' => 4]);
        $queries = $this->queriesDuring(fn (): string => $this->sitemap());

        $this->assertCount(4, $this->locations($this->sitemap()));
        $this->assertSame([], $this->readsOf('blogs', $queries));
        $this->assertSame([], $this->readsOf('forum_threads', $queries));
        $this->assertSame([], $this->readsOf('products', $queries));
        $this->assertSame([], $this->readsOf('users', $queries));
    }

    public function test_a_kind_is_asked_for_what_the_kinds_before_it_left(): void
    {
        [, , , , $members] = $this->site();

        // The tenth entry is the first thread: one thread is asked for, and no product and no member.
        config(['seo.sitemap.max_urls' => 10]);
        $queries = $this->queriesDuring(fn (): string => $this->sitemap());
        $locations = $this->locations($this->sitemap());

        $this->assertCount(10, $locations);
        $this->assertSame(route('forum.threads.show', ['board' => 'general-talk', 'thread' => 'latest-thread']), $locations[9]);
        $this->assertSame('limit 1', $this->readOf('forum_threads', $queries));
        $this->assertSame([], $this->readsOf('products', $queries));
        $this->assertSame([], $this->readsOf('users', $queries));

        // The fourteenth entry is the product: one is asked for, and no member.
        config(['seo.sitemap.max_urls' => 14]);
        $queries = $this->queriesDuring(fn (): string => $this->sitemap());

        $this->assertCount(14, $this->locations($this->sitemap()));
        $this->assertSame('limit 1', $this->readOf('products', $queries));
        $this->assertSame([], $this->readsOf('users', $queries));

        // The members come last: the one changed last is the fifteenth entry, and is the only one asked for.
        config(['seo.sitemap.max_urls' => 15]);
        $queries = $this->queriesDuring(fn (): string => $this->sitemap());
        $locations = $this->locations($this->sitemap());

        $this->assertCount(15, $locations);
        $this->assertSame(route('members.show', ['user' => $members['listed'][0]]), $locations[14]);
        $this->assertSame('limit 1', $this->readOf('users', $queries));

        // The kinds are asked for what is left, not for the cap: 13 after the blog's page, 3 members after the fourteenth entry.
        config(['seo.sitemap.max_urls' => 17]);
        $queries = $this->queriesDuring(fn (): string => $this->sitemap());

        $this->assertCount(17, $this->locations($this->sitemap()));
        $this->assertSame('limit 3', $this->readOf('users', $queries));
        $this->assertSame('limit 13', $this->readOf('blogs', $queries));

        // With room to spare, everything.
        config(['seo.sitemap.max_urls' => 100]);
        $this->assertCount(14 + count($members['listed']), $this->locations($this->sitemap()));
    }

    public function test_no_row_is_made_into_a_model(): void
    {
        $this->site();

        $made = [];

        foreach ([Blog::class, ForumThread::class, Product::class, User::class] as $class) {
            Event::listen('eloquent.retrieved: '.$class, function () use (&$made, $class): void {
                $made[$class] = ($made[$class] ?? 0) + 1;
            });
        }

        $this->assertNotEmpty($this->locations($this->sitemap()));
        $this->assertSame([], $made);
    }

    public function test_a_time_reads_the_way_a_model_reads_it_in_the_sites_timezone(): void
    {
        $blog = Blog::factory()->published()->create(['slug' => 'timed-post']);
        DB::table('blogs')->where('id', $blog->id)->update(['updated_at' => '2026-03-05 10:00:00']);

        $original = date_default_timezone_get();

        try {
            date_default_timezone_set('America/New_York');
            config(['app.timezone' => 'America/New_York']);

            $modelsTime = Blog::query()->findOrFail($blog->id)->updated_at?->format(DATE_ATOM);
            $xml = $this->sitemap();
        } finally {
            date_default_timezone_set($original);
            config(['app.timezone' => $original]);
        }

        $this->assertSame('2026-03-05T10:00:00-05:00', $modelsTime);
        $this->assertStringContainsString('<loc>'.e(route('blogs.view', ['slug' => 'timed-post'])).'</loc><lastmod>'.$modelsTime.'</lastmod>', $xml);
    }

    public function test_a_row_with_no_time_has_no_lastmod(): void
    {
        $board = $this->board();
        $thread = $this->thread($board, 'never-posted');
        DB::table('forum_threads')->where('id', $thread->id)->update(['last_posted_at' => null, 'updated_at' => null]);

        $this->assertStringContainsString(
            '  <url><loc>'.e(route('forum.threads.show', ['board' => $board->slug, 'thread' => 'never-posted'])).'</loc><priority>0.5</priority></url>',
            $this->sitemap(),
        );
    }

    public function test_a_thread_with_no_last_post_is_dated_by_its_own_change(): void
    {
        $board = $this->board();
        $thread = $this->thread($board, 'no-posts-yet');
        DB::table('forum_threads')->where('id', $thread->id)->update(['last_posted_at' => null, 'updated_at' => '2026-02-02 03:04:05']);

        $this->assertStringContainsString(
            '  <url><loc>'.e(route('forum.threads.show', ['board' => $board->slug, 'thread' => 'no-posts-yet'])).'</loc><lastmod>2026-02-02T03:04:05+00:00</lastmod><priority>0.5</priority></url>',
            $this->sitemap(),
        );
    }

    public function test_a_thread_that_comes_back_when_its_posts_change_is_listed_by_its_last_post(): void
    {
        $board = $this->board();
        $first = $this->thread($board, 'first');
        $second = $this->thread($board, 'second');
        DB::table('forum_threads')->where('id', $first->id)->update(['last_posted_at' => '2026-05-01 00:00:00']);
        DB::table('forum_threads')->where('id', $second->id)->update(['last_posted_at' => '2026-04-01 00:00:00']);

        $locations = $this->locations($this->sitemap());

        $this->assertLessThan(
            array_search(route('forum.threads.show', ['board' => $board->slug, 'thread' => 'second']), $locations, true),
            array_search(route('forum.threads.show', ['board' => $board->slug, 'thread' => 'first']), $locations, true),
        );
    }

    public function test_a_thread_is_listed_under_its_own_board(): void
    {
        $first = $this->board();
        $second = ForumBoard::create([
            'forum_category_id' => $first->forum_category_id,
            'title' => 'Other board',
            'slug' => 'other-board',
            'description' => 'Other',
            'position' => ++$this->made,
        ]);
        $this->thread($first, 'in-the-first');
        $this->thread($second, 'in-the-second');

        $locations = $this->locations($this->sitemap());

        $this->assertContains(route('forum.threads.show', ['board' => 'general-talk', 'thread' => 'in-the-first']), $locations);
        $this->assertContains(route('forum.threads.show', ['board' => 'other-board', 'thread' => 'in-the-second']), $locations);
        $this->assertNotContains(route('forum.threads.show', ['board' => 'general-talk', 'thread' => 'in-the-second']), $locations);
    }

    public function test_a_thread_in_a_board_made_while_the_sitemap_is_being_built_is_left_for_the_next_one(): void
    {
        $board = $this->board();
        $this->thread($board, 'early-thread');

        // The boards are read first and the threads after: a board made between the two is not in the list the threads are matched to.
        $made = false;
        DB::listen(function (QueryExecuted $query) use (&$made): void {
            if ($made || preg_match('/from\s+[`"]?forum_boards[`"]?/i', $query->sql) !== 1) {
                return;
            }

            $made = true;
            $late = ForumBoard::create([
                'forum_category_id' => ForumCategory::query()->value('id'),
                'title' => 'Made late',
                'slug' => 'made-late',
                'description' => 'Late',
                'position' => 99,
            ]);
            $this->thread($late, 'late-thread');
        });

        $locations = $this->locations($this->sitemap());

        $this->assertTrue($made);
        $this->assertContains(route('forum.threads.show', ['board' => 'general-talk', 'thread' => 'early-thread']), $locations);
        $this->assertNotContains(route('forum.threads.show', ['board' => 'made-late', 'thread' => 'late-thread']), $locations);
    }

    /**
     * @return array{0: array<string, string>, 1: ForumBoard, 2: array<string, string>, 3: array<string, string>, 4: array<string, User>}
     */
    private function site(): array
    {
        $newest = Blog::factory()->published()->create(['slug' => 'newest-post']);
        $older = Blog::factory()->published()->create(['slug' => 'older-post']);
        $draft = Blog::factory()->create(['slug' => 'draft-post', 'status' => 'draft']);
        DB::table('blogs')->where('id', $newest->id)->update(['published_at' => '2026-02-01 00:00:00', 'updated_at' => '2026-02-02 00:00:00']);
        DB::table('blogs')->where('id', $older->id)->update(['published_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-02 00:00:00']);

        $board = $this->board();
        $latest = $this->thread($board, 'latest-thread');
        $quiet = $this->thread($board, 'quiet-thread');
        $hidden = $this->thread($board, 'hidden-thread');
        DB::table('forum_threads')->where('id', $latest->id)->update(['last_posted_at' => '2026-03-03 00:00:00']);
        DB::table('forum_threads')->where('id', $quiet->id)->update(['last_posted_at' => '2026-03-01 00:00:00']);
        DB::table('forum_threads')->where('id', $hidden->id)->update(['is_published' => false, 'last_posted_at' => '2026-03-04 00:00:00']);

        $listed = Product::factory()->create(['slug' => 'listed-product']);
        $off = Product::factory()->inactive()->create(['slug' => 'switched-off-product']);
        DB::table('products')->where('id', $listed->id)->update(['updated_at' => '2026-04-04 00:00:00']);

        // Every member who is not banned is listed (the authors of the posts and threads too), the one changed last first. Each is given
        // a time of their own, a day apart in the order they were made, so that the order does not rest on a tie.
        $banned = User::factory()->create(['is_banned' => true]);
        $memberIds = [];

        foreach (User::query()->where('is_banned', false)->orderByDesc('id')->get() as $position => $member) {
            DB::table('users')->where('id', $member->id)->update(['updated_at' => now()->setDate(2026, 6, 1)->startOfDay()->subDays($position)->toDateTimeString()]);
            $memberIds[] = $member->id;
        }

        DB::table('users')->where('id', $banned->id)->update(['updated_at' => '2026-07-01 00:00:00']);

        return [
            ['newest' => $newest->slug, 'older' => $older->slug, 'draft' => $draft->slug],
            $board,
            ['latest' => 'latest-thread', 'quiet' => 'quiet-thread', 'hidden' => 'hidden-thread'],
            ['listed' => $listed->slug, 'off' => $off->slug],
            ['listed' => $memberIds, 'banned' => $banned],
        ];
    }

    private function board(): ForumBoard
    {
        $category = ForumCategory::query()->firstOrCreate(
            ['slug' => 'general'],
            ['title' => 'General', 'description' => 'General talk', 'position' => 1],
        );

        return ForumBoard::create([
            'forum_category_id' => $category->id,
            'title' => 'General talk',
            'slug' => 'general-talk',
            'description' => 'General talk',
            'position' => ++$this->made,
        ]);
    }

    private function thread(ForumBoard $board, string $slug): ForumThread
    {
        return ForumThread::create([
            'forum_board_id' => $board->id,
            'user_id' => ($this->author ??= User::factory()->create())->id,
            'title' => $slug,
            'slug' => $slug,
            'excerpt' => 'A thread',
            'is_locked' => false,
            'is_pinned' => false,
            'is_published' => true,
            'views' => 0,
        ]);
    }

    private function sitemap(): string
    {
        Cache::flush();

        return (string) $this->get('/sitemap.xml')->assertOk()->getContent();
    }

    /**
     * @return list<string>
     */
    private function locations(string $xml): array
    {
        preg_match_all('#<loc>(.*?)</loc>#', $xml, $matches);

        return array_map(fn (string $location): string => html_entity_decode($location), $matches[1]);
    }

    /**
     * @return list<string>
     */
    private function queriesDuring(callable $work): array
    {
        $sql = [];
        DB::listen(function (QueryExecuted $query) use (&$sql): void {
            $sql[] = $query->sql;
        });

        $work();

        return $sql;
    }

    /**
     * The `limit` of each read of a table, as written in the SQL: `limit 4`.
     *
     * @param  list<string>  $queries
     * @return list<string>
     */
    private function readsOf(string $table, array $queries): array
    {
        $limits = [];

        foreach ($queries as $sql) {
            if (preg_match('/from\s+[`"]?'.$table.'[`"]?(\s|$)/i', $sql) !== 1) {
                continue;
            }

            $limits[] = preg_match('/limit\s+(\d+)/i', $sql, $found) === 1 ? 'limit '.$found[1] : 'no limit';
        }

        return $limits;
    }

    /**
     * @param  list<string>  $queries
     */
    private function readOf(string $table, array $queries): string
    {
        $reads = $this->readsOf($table, $queries);

        $this->assertCount(1, $reads, 'The '.$table.' were read in '.count($reads).' queries.');

        return $reads[0];
    }
}
