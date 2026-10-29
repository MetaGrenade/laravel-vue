<?php

namespace Tests\Feature\Search;

use App\Models\Blog;
use App\Models\Brand;
use App\Models\Faq;
use App\Models\FaqCategory;
use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\NullEngine;
use Tests\TestCase;

class SearchIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['scout.driver' => 'collection']);
    }

    public function test_a_blog_is_indexed_only_when_a_guest_could_read_it(): void
    {
        $author = User::factory()->create(['nickname' => 'Zephyr Quinn']);

        Blog::factory()->published()->create([
            'title' => 'Visible lighthouse',
            'user_id' => $author->id,
        ]);
        Blog::factory()->create(['title' => 'Hidden lighthouse']);
        Blog::factory()->archived()->create(['title' => 'Archived lighthouse']);
        Blog::factory()->scheduled()->create(['title' => 'Future lighthouse']);
        Blog::factory()->create([
            'title' => 'Due lighthouse',
            'status' => 'scheduled',
            'scheduled_for' => now()->subMinute(),
            'published_at' => null,
        ]);

        $titles = Blog::search('lighthouse')->get()->pluck('title')->all();

        $this->assertEqualsCanonicalizing(['Visible lighthouse', 'Due lighthouse'], $titles);
        $this->assertCount(1, Blog::search('Zephyr')->get());
    }

    public function test_unpublishing_a_blog_takes_it_out_of_the_index(): void
    {
        $engine = $this->recordingEngine();

        $blog = Blog::factory()->published()->create(['title' => 'Visible lighthouse']);
        $blog->update(['status' => 'draft', 'published_at' => null]);

        $this->assertSame(1, $engine->deleted);

        config(['scout.driver' => 'collection']);

        $this->assertCount(0, Blog::search('lighthouse')->get());
    }

    public function test_a_thread_is_indexed_only_when_it_is_published(): void
    {
        $board = $this->board();

        $this->thread($board, true, 'Visible thread');
        $this->thread($board, false, 'Hidden thread');

        $titles = ForumThread::search('thread')->get()->pluck('title')->all();

        $this->assertSame(['Visible thread'], $titles);
    }

    public function test_a_held_reply_is_not_part_of_the_thread_document(): void
    {
        $thread = $this->thread($this->board(), true, 'A public thread');

        ForumPost::query()->create([
            'forum_thread_id' => $thread->id,
            'user_id' => $thread->user_id,
            'body' => 'visible reply about zephyrword',
        ]);

        $held = new ForumPost;
        $held->forceFill([
            'forum_thread_id' => $thread->id,
            'user_id' => $thread->user_id,
            'body' => 'held reply about quarksecret',
            'held_at' => now(),
        ])->save();

        $this->assertCount(1, ForumThread::search('zephyrword')->get());
        $this->assertCount(0, ForumThread::search('quarksecret')->get());
    }

    public function test_a_reply_asks_for_the_thread_to_be_indexed_again(): void
    {
        $engine = $this->recordingEngine();
        $thread = $this->thread($this->board(), true, 'A public thread');
        $updated = $engine->updated;

        ForumPost::query()->create([
            'forum_thread_id' => $thread->id,
            'user_id' => $thread->user_id,
            'body' => 'a further reply',
        ]);

        $this->assertGreaterThan($updated, $engine->updated);
    }

    public function test_a_faq_is_indexed_only_when_it_is_published(): void
    {
        $category = FaqCategory::factory()->create(['name' => 'Orbital questions']);

        Faq::factory()->create([
            'faq_category_id' => $category->id,
            'question' => 'How does the lantern work?',
            'published' => true,
        ]);
        Faq::factory()->create([
            'faq_category_id' => $category->id,
            'question' => 'How does the hidden lantern work?',
            'published' => false,
        ]);

        $questions = Faq::search('lantern')->get()->pluck('question')->all();

        $this->assertSame(['How does the lantern work?'], $questions);
        $this->assertCount(1, Faq::search('Orbital')->get());
    }

    public function test_a_product_is_indexed_only_when_it_is_for_sale(): void
    {
        $brand = Brand::query()->create([
            'name' => 'Northwind',
            'slug' => 'northwind',
        ]);

        Product::factory()->create([
            'name' => 'Shown lantern',
            'brand_id' => $brand->id,
        ]);
        Product::factory()->inactive()->create(['name' => 'Hidden lantern']);

        $names = Product::search('lantern')->get()->pluck('name')->all();

        $this->assertSame(['Shown lantern'], $names);
        $this->assertCount(1, Product::search('Northwind')->get());
    }

    private function board(): ForumBoard
    {
        $category = ForumCategory::query()->create([
            'title' => 'General',
            'slug' => 'general-'.Str::lower(Str::random(4)),
            'position' => 1,
        ]);

        return ForumBoard::query()->create([
            'forum_category_id' => $category->id,
            'title' => 'Talk',
            'slug' => 'talk-'.Str::lower(Str::random(4)),
            'description' => 'Talk',
            'position' => 1,
        ]);
    }

    private function thread(ForumBoard $board, bool $published, string $title): ForumThread
    {
        return ForumThread::query()->create([
            'forum_board_id' => $board->id,
            'user_id' => User::factory()->create()->id,
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(4)),
            'excerpt' => '',
            'is_locked' => false,
            'is_pinned' => false,
            'is_published' => $published,
            'views' => 0,
            'last_posted_at' => now(),
        ]);
    }

    private function recordingEngine(): RecordingSearchEngine
    {
        $engine = new RecordingSearchEngine;
        $this->app->make(EngineManager::class)->extend('recording', fn () => $engine);
        config(['scout.driver' => 'recording']);

        return $engine;
    }
}

class RecordingSearchEngine extends NullEngine
{
    public int $updated = 0;

    public int $deleted = 0;

    public function update($models): void
    {
        $this->updated += count($models);
    }

    public function delete($models): void
    {
        $this->deleted += count($models);
    }
}
