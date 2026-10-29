<?php

namespace Tests\Feature\Search;

use App\Models\Blog;
use App\Models\Brand;
use App\Models\Faq;
use App\Models\FaqCategory;
use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumThread;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Scout\Contracts\UpdatesIndexSettings;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\NullEngine;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReindexSearchCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_refuses_to_reindex_when_the_driver_is_the_database(): void
    {
        $this->assertSame('database', config('search.driver'));

        $this->artisan('metaforge:search:reindex')
            ->expectsOutputToContain('Set SEARCH_DRIVER=meilisearch')
            ->assertFailed();
    }

    #[Test]
    public function it_flushes_and_imports_only_searchable_records(): void
    {
        $author = User::factory()->create();
        $published = Blog::factory()->published()->create([
            'title' => 'Visible lighthouse',
            'user_id' => $author->id,
        ]);
        Blog::factory()->create(['title' => 'Hidden lighthouse']);

        $board = $this->board();
        $visibleThread = $this->thread($board, true, 'Visible thread');
        $this->thread($board, false, 'Hidden thread');

        $category = FaqCategory::factory()->create();
        $publishedFaq = Faq::factory()->create([
            'faq_category_id' => $category->id,
            'question' => 'How does the lantern work?',
            'published' => true,
        ]);
        Faq::factory()->create([
            'faq_category_id' => $category->id,
            'question' => 'How does the hidden lantern work?',
            'published' => false,
        ]);

        $brand = Brand::query()->create(['name' => 'Northwind', 'slug' => 'northwind']);
        $active = Product::factory()->create([
            'name' => 'Shown lantern',
            'brand_id' => $brand->id,
        ]);
        Product::factory()->inactive()->create(['name' => 'Hidden lantern']);

        $engine = $this->recordingMeilisearch();

        $this->artisan('metaforge:search:reindex')
            ->expectsOutputToContain('Index settings synced')
            ->expectsOutputToContain('[blogs]')
            ->expectsOutputToContain('[forum_threads]')
            ->expectsOutputToContain('[faqs]')
            ->expectsOutputToContain('[products]')
            ->assertSuccessful();

        $this->assertSame(
            ['blogs', 'forum_threads', 'faqs', 'products'],
            $engine->flushed,
        );
        $this->assertSame(
            ['blogs', 'forum_threads', 'faqs', 'products'],
            array_keys($engine->settings),
        );

        $this->assertSame([$published->id], $engine->updatedIds[Blog::class] ?? []);
        $this->assertSame([$visibleThread->id], $engine->updatedIds[ForumThread::class] ?? []);
        $this->assertSame([$publishedFaq->id], $engine->updatedIds[Faq::class] ?? []);
        $this->assertSame([$active->id], $engine->updatedIds[Product::class] ?? []);
    }

    #[Test]
    public function it_can_skip_pushing_settings(): void
    {
        Blog::factory()->published()->create(['title' => 'Visible lighthouse']);

        $engine = $this->recordingMeilisearch();

        $this->artisan('metaforge:search:reindex', ['--skip-sync' => true])
            ->assertSuccessful();

        $this->assertSame([], $engine->settings);
        $this->assertContains('blogs', $engine->flushed);
        $this->assertNotEmpty($engine->updatedIds[Blog::class] ?? []);
    }

    private function recordingMeilisearch(): RecordingReindexEngine
    {
        $engine = new RecordingReindexEngine;
        $this->app->make(EngineManager::class)->extend('meilisearch', fn () => $engine);

        config([
            'search.driver' => 'meilisearch',
            'scout.driver' => 'meilisearch',
            'scout.prefix' => '',
            'scout.queue' => false,
        ]);

        return $engine;
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
}

class RecordingReindexEngine extends NullEngine implements UpdatesIndexSettings
{
    /** @var list<string> */
    public array $flushed = [];

    /** @var array<string, array<string, mixed>> */
    public array $settings = [];

    /** @var array<class-string, list<int|string>> */
    public array $updatedIds = [];

    public function flush($model): void
    {
        $this->flushed[] = $model->indexableAs();
    }

    public function update($models): void
    {
        foreach ($models as $model) {
            $this->updatedIds[$model::class][] = $model->getKey();
        }
    }

    public function updateIndexSettings($name, array $settings = []): void
    {
        $this->settings[$name] = $settings;
    }

    public function configureSoftDeleteFilter(array $settings = []): array
    {
        return $settings;
    }
}
