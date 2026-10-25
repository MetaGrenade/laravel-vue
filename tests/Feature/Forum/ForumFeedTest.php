<?php

namespace Tests\Feature\Forum;

use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumPrefix;
use App\Models\ForumThread;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\Forum\ForumFeed;
use App\Support\WebsiteSections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The public Atom feed of published threads (docs/forum-rss.md).
 */
class ForumFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_feed_lists_the_latest_published_threads(): void
    {
        $author = User::factory()->create();
        $help = $this->board('Help', 'help-board');
        $news = $this->board('News', 'news-board');
        $question = ForumPrefix::create([
            'name' => 'Q&A',
            'slug' => 'q-and-a',
            'color' => 'slate',
            'position' => 1,
        ]);

        $quiet = $this->thread($help, $author, 'Already settled', Carbon::parse('2026-04-01 09:00:00'), excerpt: 'Nothing new');
        $active = $this->thread(
            $help,
            $author,
            'Hello <script>',
            Carbon::parse('2026-04-03 09:00:00'),
            $question,
            'A note & more',
        );
        $this->thread($news, $author, 'Fresh announcement', Carbon::parse('2026-04-02 09:00:00'));
        $this->thread($help, $author, 'Held back', Carbon::parse('2026-04-04 09:00:00'), published: false);

        $response = $this->get(route('forum.feed'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/atom+xml; charset=UTF-8');

        $xml = simplexml_load_string($response->getContent());
        $this->assertNotFalse($xml);
        $this->assertSame('feed', $xml->getName());
        $this->assertSame(config('app.name').' Forum', (string) $xml->title);
        $this->assertSame(route('forum.feed'), (string) $xml->id);
        $this->assertSame(route('forum.index'), (string) $xml->link[1]['href']);
        $this->assertCount(3, $xml->entry);
        $this->assertStringNotContainsString('<script>', $response->getContent());

        $first = $xml->entry[0];
        $this->assertSame('Q&A: Hello <script>', (string) $first->title);
        $this->assertSame(route('forum.threads.show', [$help, $active]), (string) $first->link['href']);
        $this->assertSame('help-board', (string) $first->category['term']);
        $this->assertSame('Help', (string) $first->category['label']);
        $this->assertSame('A note & more', trim((string) $first->summary));
        $this->assertSame($active->last_posted_at?->toAtomString(), (string) $first->updated);

        $this->assertSame($quiet->title, (string) $xml->entry[2]->title);
        $this->assertStringNotContainsString('Held back', $response->getContent());
    }

    public function test_a_board_feed_is_only_that_board_and_stops_after_twenty(): void
    {
        $author = User::factory()->create();
        $help = $this->board('Help', 'help-board');
        $news = $this->board('News', 'news-board');

        $kept = [];

        for ($number = 1; $number <= ForumFeed::LATEST + 1; $number++) {
            $kept[] = $this->thread(
                $help,
                $author,
                sprintf('Line %02d', $number),
                Carbon::parse('2026-04-01 09:00:00')->addMinutes($number),
            );
        }

        $this->thread($news, $author, 'Other board', Carbon::parse('2026-05-01 09:00:00'));

        $response = $this->get(route('forum.boards.feed', $help));

        $response->assertOk();
        $xml = simplexml_load_string($response->getContent());
        $this->assertNotFalse($xml);
        $this->assertSame(config('app.name').' Forum: Help', (string) $xml->title);
        $this->assertSame(route('forum.boards.feed', $help), (string) $xml->id);
        $this->assertSame(route('forum.boards.show', $help), (string) $xml->link[1]['href']);
        $this->assertCount(ForumFeed::LATEST, $xml->entry);
        $this->assertSame('Line 21', (string) $xml->entry[0]->title);
        $this->assertSame('Line 02', (string) $xml->entry[ForumFeed::LATEST - 1]->title);
        $this->assertStringNotContainsString('Line 01', $response->getContent());
        $this->assertStringNotContainsString('Other board', $response->getContent());
        $this->assertSame(route('forum.threads.show', [$help, $kept[ForumFeed::LATEST]]), (string) $xml->entry[0]->link['href']);
    }

    public function test_the_feed_is_missing_when_the_forum_is_off(): void
    {
        $sections = WebsiteSections::defaults();
        $sections[WebsiteSections::FORUM] = false;
        SystemSetting::set('website_sections', $sections);

        $this->get(route('forum.feed'))->assertNotFound();
        $this->get('/forum/help-board/feed')->assertNotFound();
    }

    private function board(string $title, string $slug): ForumBoard
    {
        $category = ForumCategory::query()->firstOrCreate(
            ['slug' => 'general'],
            [
                'title' => 'General',
                'description' => 'Chat',
                'position' => 1,
            ],
        );

        return ForumBoard::create([
            'forum_category_id' => $category->id,
            'title' => $title,
            'slug' => $slug,
            'description' => null,
            'position' => 1,
        ]);
    }

    private function thread(
        ForumBoard $board,
        User $author,
        string $title,
        Carbon $activity,
        ?ForumPrefix $prefix = null,
        ?string $excerpt = null,
        bool $published = true,
    ): ForumThread {
        return ForumThread::create([
            'forum_board_id' => $board->id,
            'forum_prefix_id' => $prefix?->id,
            'user_id' => $author->id,
            'title' => $title,
            'slug' => $board->slug.'-'.substr(sha1($title.$activity->toIso8601String()), 0, 8),
            'excerpt' => $excerpt,
            'is_locked' => false,
            'is_pinned' => false,
            'is_published' => $published,
            'views' => 0,
            'last_posted_at' => $activity,
            'last_post_user_id' => $author->id,
        ]);
    }
}
