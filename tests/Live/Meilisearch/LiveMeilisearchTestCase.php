<?php

namespace Tests\Live\Meilisearch;

use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumThread;
use App\Models\User;
use App\Support\Search\SearchIndexSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Scout\EngineManager;
use Meilisearch\Client;
use Meilisearch\Contracts\TasksQuery;
use Tests\TestCase;
use Throwable;

/**
 * A test against a real Meilisearch (docs/search-index.md). These are not in the suites `php artisan test` runs: the CI search job runs
 * them with `php artisan test tests/Live`, against a Meilisearch container, and they skip themselves unless `MEILISEARCH_LIVE_HOST` (and
 * `MEILISEARCH_LIVE_KEY`) say where one is.
 *
 * Every run uses its own index prefix and removes its indexes afterwards, so it never touches the indexes a site is using.
 */
abstract class LiveMeilisearchTestCase extends TestCase
{
    use RefreshDatabase;

    protected string $prefix;

    protected Client $meili;

    protected function setUp(): void
    {
        $host = getenv('MEILISEARCH_LIVE_HOST');

        if (! is_string($host) || $host === '') {
            $this->markTestSkipped('Set MEILISEARCH_LIVE_HOST and MEILISEARCH_LIVE_KEY to run these against a real Meilisearch.');
        }

        parent::setUp();

        $key = getenv('MEILISEARCH_LIVE_KEY');
        $this->prefix = 'live_'.bin2hex(random_bytes(4)).'_';
        $this->useMeilisearch($host, is_string($key) && $key !== '' ? $key : null);
    }

    protected function tearDown(): void
    {
        if (isset($this->meili)) {
            foreach ($this->indexNames() as $name) {
                try {
                    $this->meili->deleteIndex($name);
                } catch (Throwable) {
                    // It may never have been made.
                }
            }
        }

        parent::tearDown();
    }

    /**
     * Point the app at this Meilisearch, with this key, under this run's prefix.
     */
    protected function useMeilisearch(string $host, ?string $key): void
    {
        config([
            'search.driver' => 'meilisearch',
            'scout.driver' => 'meilisearch',
            'scout.prefix' => $this->prefix,
            'scout.meilisearch.host' => $host,
            'scout.meilisearch.key' => $key,
        ]);

        // Scout reads the host and key when it builds its engine, so what it built for the last one has to go.
        $this->app->forgetInstance(EngineManager::class);
        $this->app->forgetInstance(Client::class);

        $this->meili = new Client($host, $key);
    }

    /**
     * @return list<string>
     */
    protected function indexNames(): array
    {
        return array_map(fn (string $model) => (new $model)->indexableAs(), array_keys(SearchIndexSettings::forScout()));
    }

    /**
     * Wait until Meilisearch has done everything it was asked for: it answers an update at once and does it afterwards.
     */
    protected function settle(): void
    {
        $deadline = microtime(true) + 20;

        do {
            $pending = $this->meili
                ->getTasks((new TasksQuery)->setStatuses(['enqueued', 'processing'])->setIndexUids($this->indexNames())->setLimit(1))
                ->getTotal();

            if ($pending === 0) {
                return;
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        $this->fail('Meilisearch did not finish its updates within 20 seconds.');
    }

    protected function documentsIn(string $model): int
    {
        return (int) $this->meili->index((new $model)->indexableAs())->stats()['numberOfDocuments'];
    }

    protected function author(): User
    {
        return User::factory()->create(['nickname' => 'Quill Authorson '.bin2hex(random_bytes(3))]);
    }

    protected function board(): ForumBoard
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

    protected function thread(ForumBoard $board, string $title, bool $published = true): ForumThread
    {
        return ForumThread::query()->create([
            'forum_board_id' => $board->id,
            'user_id' => $this->author()->id,
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
