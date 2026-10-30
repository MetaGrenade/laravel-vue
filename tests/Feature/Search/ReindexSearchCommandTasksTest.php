<?php

namespace Tests\Feature\Search;

use App\Models\Blog;
use App\Support\Search\MeilisearchCircuitBreaker;
use App\Support\Search\SearchHealth;
use App\Support\Search\SearchIndexState;
use App\Support\Search\SearchTasks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Sleep;
use Laravel\Scout\Contracts\UpdatesIndexSettings;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\CollectionEngine;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeMeilisearch;
use Tests\TestCase;

/**
 * What `metaforge:search:reindex` does about Meilisearch's tasks (docs/search-index.md). Meilisearch answers an update at once, does it
 * afterwards, and may refuse it. So the rebuild notes where it BEGAN before it sends anything, waits for its updates to finish, and counts
 * as DONE only if none was refused; the health check counts refusals from the same point, so those of the rebuild itself are not lost to
 * the time the command happened to finish.
 */
class ReindexSearchCommandTasksTest extends TestCase
{
    use RefreshDatabase;

    /** What a rebuild sends first, in order: the settings of each index, then the flushing of each. */
    private const SENT = ['settings', 'settings', 'settings', 'settings', 'flush', 'flush', 'flush', 'flush'];

    /** @var list<array<string, mixed>> Meilisearch's tasks, which the engine adds to as the rebuild sends its updates. */
    private array $log = [];

    private TaskLoggingEngine $engine;

    private FakeMeilisearch $meilisearch;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        config(['search.driver' => 'meilisearch', 'scout.driver' => 'meilisearch', 'scout.prefix' => '', 'scout.queue' => false]);

        // Saved without telling the index: only the rebuild talks to Meilisearch here.
        Blog::withoutSyncingToSearch(fn () => Blog::factory()->published()->create());

        // Eight tasks from before: the rebuild begins at the ninth.
        for ($uid = 0; $uid < 8; $uid++) {
            $this->log[] = FakeMeilisearch::task($uid, 'succeeded', 'blogs');
        }
    }

    /**
     * Meilisearch with the tasks of $this->log, and an engine that adds a task for each index the rebuild flushes, with the status
     * (and the reason, for a refusal) the test gives for it.
     *
     * @param  array<string, string|array{string, string}>  $sends  By index: a status, or a status and the reason it was refused.
     */
    private function rebuildSends(array $sends, ?\Closure $onAsk = null): void
    {
        $this->meilisearch = FakeMeilisearch::serving()->taskLog($this->log, $onAsk);
        $this->app->instance(SearchTasks::class, new SearchTasks($this->meilisearch->client()));

        $this->engine = $engine = new TaskLoggingEngine(function (string $index) use ($sends) {
            [$status, $reason] = (array) ($sends[$index] ?? 'succeeded') + [1 => null];
            $this->log[] = FakeMeilisearch::task(count($this->log), $status, $index, $reason);
        });
        // A local, not $this->engine: the manager runs this closure as its own, so `$this` in it would be the manager.
        $this->app->make(EngineManager::class)->extend('meilisearch', fn () => $engine);
    }

    /**
     * Run the command and give its exit code and everything it printed, in one line: a long message wraps, and Laravel matches one
     * expected line to one write, so the words are looked for in the whole.
     *
     * @param  array<string, mixed>  $options
     * @return array{int, string}
     */
    private function reindex(array $options = []): array
    {
        $code = Artisan::call('metaforge:search:reindex', $options);

        return [$code, trim((string) preg_replace('/\s+/', ' ', Artisan::output()))];
    }

    // --- Done only when Meilisearch has done it ----------------------------------------------------------------------------------

    #[Test]
    public function a_rebuild_whose_updates_all_succeeded_is_recorded_as_done(): void
    {
        $this->rebuildSends([]);

        [$code, $output] = $this->reindex();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Meilisearch finished the rebuild and refused nothing.', $output);
        $this->assertNotNull(SearchIndexState::lastReindexedAt());
        $this->assertSame(8, SearchIndexState::lastReindexFromTask(), 'the ninth task: nothing before it is the rebuild\'s');
    }

    #[Test]
    public function where_the_rebuild_began_is_noted_before_it_sends_anything(): void
    {
        $this->rebuildSends([]);

        $this->reindex();

        $this->assertSame(8, $this->engine->fromWhenFirstCalled, 'known at the first thing it sent, so that none of its updates falls before it');
    }

    #[Test]
    public function an_update_meilisearch_refused_makes_the_rebuild_fail_and_it_is_not_recorded_as_done(): void
    {
        $this->rebuildSends(['forum_threads' => ['failed', 'Document does not have a `nope` attribute.']]);

        [$code, $output] = $this->reindex();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Meilisearch refused 1 update in this rebuild: Document does not have a `nope` attribute. The rebuild is not recorded as done.', $output);
        $this->assertStringNotContainsString('finished the rebuild', $output);
        $this->assertNull(SearchIndexState::lastReindexedAt());
        $this->assertSame(8, SearchIndexState::lastReindexFromTask(), 'where it began is on record, so what it sent is counted from there');
    }

    #[Test]
    public function the_health_check_then_shows_what_the_rebuild_itself_had_refused(): void
    {
        // The refusals were enqueued before the command finished and stamped its time: they are the rebuild's own, and still counted.
        $this->rebuildSends([
            'blogs' => ['failed', 'First refused.'],
            'forum_threads' => 'succeeded',
            'faqs' => ['failed', 'Second refused.'],
        ]);

        [$code] = $this->reindex();

        $report = (new SearchHealth($this->meilisearch->client(), new MeilisearchCircuitBreaker))->check();

        $this->assertSame(1, $code);
        $this->assertSame(2, $report->failedTasks);
        $this->assertContains('Meilisearch refused 2 updates since the last rebuild began: First refused.', $report->problems);
        $this->assertNull($report->lastReindexedAt, 'and it is not shown as rebuilt');
    }

    #[Test]
    public function a_refusal_that_came_before_the_rebuild_does_not_fail_it(): void
    {
        $this->log[3] = FakeMeilisearch::task(3, 'failed', 'blogs', 'Refused long ago.');
        $this->rebuildSends([]);

        [$code] = $this->reindex();

        $this->assertSame(0, $code);
        $this->assertNotNull(SearchIndexState::lastReindexedAt());
    }

    #[Test]
    public function a_refusal_that_only_comes_after_the_command_has_sent_everything_still_fails_it(): void
    {
        // Meilisearch is still working when the command has sent its last update, and refuses one of them while the command waits.
        // The tasks are asked for once to say where to begin, twice while waiting, and the third time (the fourth ask) they are done.
        $this->rebuildSends(
            ['blogs' => 'processing', 'forum_threads' => 'enqueued', 'faqs' => 'enqueued', 'products' => 'enqueued'],
            function (int $asked) {
                if ($asked === 4) {
                    $this->log[8]['status'] = 'failed';
                    $this->log[8]['error'] = ['message' => 'Refused while the command waited.', 'code' => 'x', 'type' => 'invalid_request', 'link' => 'https://docs.meilisearch.com/errors'];
                    $this->log[9]['status'] = 'succeeded';
                    $this->log[10]['status'] = 'succeeded';
                    $this->log[11]['status'] = 'succeeded';
                }
            },
        );

        [$code, $output] = $this->reindex();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Meilisearch refused 1 update in this rebuild: Refused while the command waited.', $output);
        $this->assertNull(SearchIndexState::lastReindexedAt());
        Sleep::assertSleptTimes(2);
    }

    // --- Waiting -----------------------------------------------------------------------------------------------------------------------

    #[Test]
    public function it_waits_for_meilisearch_and_records_the_rebuild_when_it_finishes_cleanly(): void
    {
        $this->rebuildSends(
            ['blogs' => 'processing', 'forum_threads' => 'enqueued', 'faqs' => 'enqueued', 'products' => 'enqueued'],
            function (int $asked) {
                if ($asked === 4) {
                    foreach ([8, 9, 10, 11] as $uid) {
                        $this->log[$uid]['status'] = 'succeeded';
                    }
                }
            },
        );

        [$code] = $this->reindex();

        $this->assertSame(0, $code);
        $this->assertNotNull(SearchIndexState::lastReindexedAt());
        Sleep::assertSleptTimes(2);
    }

    #[Test]
    public function a_meilisearch_that_has_not_finished_in_time_fails_the_rebuild_without_recording_it(): void
    {
        $this->rebuildSends(['blogs' => 'processing']);

        [$code, $output] = $this->reindex(['--wait' => 2]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Meilisearch had not finished after 2 seconds, so the rebuild is not recorded as done.', $output);
        $this->assertStringContainsString('run php artisan metaforge:search:health to see when it has', $output);
        $this->assertNull(SearchIndexState::lastReindexedAt());
        $this->assertSame(8, SearchIndexState::lastReindexFromTask(), 'but where it began is noted, so what it was refused is counted when it finishes');
    }

    #[Test]
    public function the_time_to_wait_comes_from_the_settings_and_the_option_overrides_it(): void
    {
        $this->rebuildSends(['blogs' => 'processing']);
        config(['search.reindex.wait_seconds' => 1]);

        $this->assertStringContainsString('had not finished after 1 seconds', $this->reindex()[1]);
        $this->assertStringContainsString('had not finished after 3 seconds', $this->reindex(['--wait' => 3])[1]);
    }

    #[Test]
    public function with_no_time_to_wait_it_sends_the_rebuild_and_says_it_is_not_recorded_as_done(): void
    {
        $this->rebuildSends(['blogs' => ['failed', 'Refused, and nobody waited to see.']]);

        [$code, $output] = $this->reindex(['--wait' => 0]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Not waiting for Meilisearch, so the rebuild is not recorded as done.', $output);
        $this->assertSame(self::SENT, array_slice($this->engine->calls, 0, 8));
        $this->assertNull(SearchIndexState::lastReindexedAt());
        $this->assertSame(8, SearchIndexState::lastReindexFromTask(), 'where it began is noted all the same');
        $this->assertFalse($this->meilisearch->asked('GET /tasks?statuses=enqueued'), 'and nothing was waited for');
    }

    #[Test]
    public function a_wait_below_nothing_is_no_wait(): void
    {
        $this->rebuildSends([]);

        [$code, $output] = $this->reindex(['--wait' => -5]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Not waiting for Meilisearch', $output);
        $this->assertNull(SearchIndexState::lastReindexedAt());
    }

    #[Test]
    public function skipping_the_settings_still_waits_and_records(): void
    {
        $this->rebuildSends([]);

        [$code] = $this->reindex(['--skip-sync' => true]);

        $this->assertSame(0, $code);
        $this->assertNotContains('settings', $this->engine->calls);
        $this->assertNotNull(SearchIndexState::lastReindexedAt());
    }

    // --- When Meilisearch will not say -----------------------------------------------------------------------------------------------

    #[Test]
    public function a_key_that_may_not_read_tasks_stops_the_rebuild_before_it_does_anything_and_says_which_action(): void
    {
        $this->rebuildSends([]);
        $this->meilisearch->refusing('GET /tasks', 403);

        [$code, $output] = $this->reindex();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('tasks.get', $output);
        $this->assertStringContainsString('--wait=0', $output);
        $this->assertSame([], $this->engine->calls, 'nothing was sent');
        $this->assertNull(SearchIndexState::lastReindexedAt());
        $this->assertNull(SearchIndexState::lastReindexFromTask());
    }

    #[Test]
    public function the_same_key_can_rebuild_without_waiting(): void
    {
        $this->rebuildSends([]);
        $this->meilisearch->refusing('GET /tasks', 403);

        [$code] = $this->reindex(['--wait' => 0]);

        $this->assertSame(0, $code);
        $this->assertSame(self::SENT, array_slice($this->engine->calls, 0, 8));
        $this->assertNull(SearchIndexState::lastReindexedAt());
        $this->assertNull(SearchIndexState::lastReindexFromTask(), 'no point to count from could be had');
    }

    #[Test]
    public function a_missing_key_is_said_in_the_same_way(): void
    {
        $this->rebuildSends([]);
        $this->meilisearch->refusing('GET /tasks', 401, 'missing_authorization_header');

        [$code, $output] = $this->reindex();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('tasks.get', $output);
        $this->assertSame([], $this->engine->calls);
    }

    #[Test]
    public function a_meilisearch_that_cannot_be_reached_fails_before_anything_is_sent(): void
    {
        $this->rebuildSends([]);
        $this->meilisearch->unreachable('GET /tasks');

        [$code, $output] = $this->reindex();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Failed to connect', $output);
        $this->assertSame([], $this->engine->calls);
        $this->assertNull(SearchIndexState::lastReindexFromTask());
    }

    #[Test]
    public function any_other_refusal_to_say_where_to_begin_is_the_error_it_is(): void
    {
        $this->rebuildSends([]);
        $this->meilisearch->refusing('GET /tasks', 500, 'internal', 'internal');

        [$code, $output] = $this->reindex();

        $this->assertSame(1, $code);
        $this->assertStringNotContainsString('tasks.get', $output);
        $this->assertSame([], $this->engine->calls);
    }

    #[Test]
    public function the_command_still_refuses_the_database_driver(): void
    {
        $this->rebuildSends([]);
        config(['search.driver' => 'database', 'scout.driver' => 'null']);

        [$code, $output] = $this->reindex();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Set SEARCH_DRIVER=meilisearch', $output);
        $this->assertNull(SearchIndexState::lastReindexFromTask());
        $this->assertSame([], $this->meilisearch->requests, 'and asked Meilisearch nothing');
    }
}

/**
 * Takes the settings and the documents without a server, notes what it was asked to do and in which order, and tells the test when an
 * index is flushed (the first thing a rebuild sends for it) so that the test can add to Meilisearch's tasks as a real one would.
 */
class TaskLoggingEngine extends CollectionEngine implements UpdatesIndexSettings
{
    /** @var list<string> */
    public array $calls = [];

    /** What was on record as the rebuild's beginning when the first thing was sent. */
    public ?int $fromWhenFirstCalled = null;

    public function __construct(private readonly \Closure $onFlush) {}

    public function updateIndexSettings($name, array $settings = []): void
    {
        $this->note('settings');
    }

    public function configureSoftDeleteFilter(array $settings = []): array
    {
        return $settings;
    }

    public function flush($model): void
    {
        $this->note('flush');
        ($this->onFlush)((new $model)->indexableAs());

        parent::flush($model);
    }

    private function note(string $call): void
    {
        if ($this->calls === []) {
            $this->fromWhenFirstCalled = SearchIndexState::lastReindexFromTask();
        }

        $this->calls[] = $call;
    }
}
