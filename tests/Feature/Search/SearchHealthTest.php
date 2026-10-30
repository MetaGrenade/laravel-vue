<?php

namespace Tests\Feature\Search;

use App\Models\Blog;
use App\Models\Faq;
use App\Models\FaqCategory;
use App\Models\ForumThread;
use App\Support\Search\MeilisearchCircuitBreaker;
use App\Support\Search\SearchHealth;
use App\Support\Search\SearchHealthReport;
use App\Support\Search\SearchIndexState;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\CollectionEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeMeilisearch;
use Tests\TestCase;

/**
 * What the ACP card and `metaforge:search:health` find (docs/search-index.md), through the real Meilisearch client and a stand-in that
 * answers by path.
 */
class SearchHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'search.driver' => 'meilisearch',
            'scout.driver' => 'meilisearch',
            'scout.prefix' => '',
            'scout.meilisearch.host' => 'http://meili.test',
            'scout.meilisearch.key' => 'fake-key',
            // Indexed on the queue, which is what a site that cares about an outage sets: the note about the other way has its own tests.
            'scout.queue' => true,
        ]);

        // A row that is saved is indexed by Scout, which must not go looking for a host: only the health check talks to the stand-in.
        $this->app->make(EngineManager::class)->extend('meilisearch', fn () => new CollectionEngine);
    }

    private function check(FakeMeilisearch $fake): SearchHealthReport
    {
        return (new SearchHealth($fake->client(), new MeilisearchCircuitBreaker))->check();
    }

    // --- The client it uses -------------------------------------------------------------------------------------------------------

    private function reach(object $object, string $property): mixed
    {
        return (new \ReflectionProperty($object, $property))->getValue($object);
    }

    #[Test]
    public function the_check_asks_the_configured_host_with_the_configured_key_and_gives_up_after_the_configured_seconds(): void
    {
        config([
            'search.health.timeout_seconds' => 5,
            'scout.meilisearch.host' => 'http://search.example.test:7700',
            'scout.meilisearch.key' => 'a-configured-key',
        ]);

        $client = $this->reach(app(SearchHealth::class), 'client');
        $adapter = $this->reach($client, 'http');
        $guzzle = $this->reach($adapter, 'http');

        $this->assertSame('http://search.example.test:7700', $this->reach($adapter, 'baseUrl'));
        $this->assertSame('Bearer a-configured-key', $this->reach($adapter, 'headers')['Authorization']);
        $this->assertSame(5, $guzzle->getConfig('timeout'));
        $this->assertSame(2, $guzzle->getConfig('connect_timeout'), 'a host that does not answer a connection is given up on sooner');
    }

    #[Test]
    public function a_timeout_of_a_second_or_less_is_one_second_and_a_missing_key_sends_none(): void
    {
        config(['search.health.timeout_seconds' => 0, 'scout.meilisearch.key' => null]);

        $adapter = $this->reach($this->reach(app(SearchHealth::class), 'client'), 'http');
        $guzzle = $this->reach($adapter, 'http');

        $this->assertSame(1, $guzzle->getConfig('timeout'));
        $this->assertSame(1, $guzzle->getConfig('connect_timeout'));
        $this->assertArrayNotHasKey('Authorization', $this->reach($adapter, 'headers'));

        // A key that is set but empty (`MEILISEARCH_KEY=` in .env) is no key either.
        config(['scout.meilisearch.key' => '']);
        $adapter = $this->reach($this->reach(app(SearchHealth::class), 'client'), 'http');
        $this->assertArrayNotHasKey('Authorization', $this->reach($adapter, 'headers'));
    }

    // --- When it is not in use ----------------------------------------------------------------------------------------------------

    #[Test]
    public function there_is_nothing_to_check_when_search_uses_the_database(): void
    {
        config(['search.driver' => 'database', 'scout.driver' => 'null']);
        $fake = FakeMeilisearch::serving();

        $report = $this->check($fake);

        $this->assertSame(SearchHealthReport::UNUSED, $report->status);
        $this->assertSame('database', $report->driver);
        $this->assertTrue($report->ok());
        $this->assertSame([], $fake->requests, 'nothing is asked of a Meilisearch that is not in use');
    }

    // --- Healthy -------------------------------------------------------------------------------------------------------------------

    #[Test]
    public function meilisearch_with_the_coded_settings_and_every_document_is_healthy(): void
    {
        $report = $this->check(FakeMeilisearch::serving());

        $this->assertSame(SearchHealthReport::HEALTHY, $report->status);
        $this->assertTrue($report->ok());
        $this->assertSame('http://meili.test', $report->host);
        $this->assertSame('1.12.8', $report->version);
        $this->assertSame([], $report->problems);
        $this->assertSame([], $report->notes);
        $this->assertSame(['blogs', 'forum_threads', 'faqs', 'products'], array_column($report->indexes, 'name'));
        $this->assertSame(array_fill(0, 4, true), array_column($report->indexes, 'settings_in_sync'));
    }

    #[Test]
    public function what_should_be_there_is_what_a_guest_could_open(): void
    {
        Blog::factory()->published()->count(2)->create();
        Blog::factory()->create(['title' => 'A draft']);
        $category = FaqCategory::factory()->create();
        Faq::create(['faq_category_id' => $category->id, 'question' => 'Is it open?', 'answer' => 'Yes.', 'order' => 1, 'published' => true]);
        Faq::create(['faq_category_id' => $category->id, 'question' => 'Is it hidden?', 'answer' => 'Yes.', 'order' => 2, 'published' => false]);

        $report = $this->check(FakeMeilisearch::serving(['blogs' => 2, 'faqs' => 1]));

        $this->assertSame(SearchHealthReport::HEALTHY, $report->status);
        $this->assertSame(['blogs' => 2, 'forum_threads' => 0, 'faqs' => 1, 'products' => 0], array_column($report->indexes, 'expected', 'name'));
        $this->assertSame(['blogs' => 2, 'forum_threads' => 0, 'faqs' => 1, 'products' => 0], array_column($report->indexes, 'documents', 'name'));
    }

    #[Test]
    public function a_key_that_may_not_ask_for_the_version_is_still_healthy(): void
    {
        $report = $this->check(FakeMeilisearch::serving()->refusing('GET /version', 403, 'invalid_api_key'));

        $this->assertSame(SearchHealthReport::HEALTHY, $report->status);
        $this->assertNull($report->version);
    }

    #[Test]
    public function a_key_that_may_not_read_tasks_is_not_a_problem(): void
    {
        $report = $this->check(FakeMeilisearch::serving()->refusing('GET /tasks', 403));

        $this->assertSame(SearchHealthReport::HEALTHY, $report->status);
        $this->assertSame(0, $report->failedTasks);
    }

    // --- Down ----------------------------------------------------------------------------------------------------------------------

    #[Test]
    public function a_host_that_cannot_be_reached_is_down_and_search_is_said_to_be_on_the_database(): void
    {
        $report = $this->check(FakeMeilisearch::serving()->unreachable());

        $this->assertSame(SearchHealthReport::DOWN, $report->status);
        $this->assertFalse($report->ok());
        $this->assertCount(1, $report->problems);
        $this->assertStringContainsString('cannot be reached at http://meili.test', $report->problems[0]);
        $this->assertStringContainsString('served from the database', $report->problems[0]);
        $this->assertStringContainsString('Failed to connect', $report->problems[0], 'and why, as far as the client says');
        $this->assertStringNotContainsString('(see https://', $report->problems[0], 'without the pointer cURL adds');
        $this->assertSame([], $report->indexes);
    }

    #[Test]
    public function a_host_that_stops_answering_part_way_is_down(): void
    {
        $fake = FakeMeilisearch::serving()->on('GET /indexes/faqs/stats', new ConnectException('Operation timed out', new Request('GET', 'http://meili.test')));

        $report = $this->check($fake);

        $this->assertSame(SearchHealthReport::DOWN, $report->status);
        $this->assertStringContainsString('stopped answering at http://meili.test', $report->problems[0]);
    }

    #[Test]
    public function no_key_at_all_is_down_and_says_to_set_one(): void
    {
        $report = $this->check(FakeMeilisearch::serving()->refusing('GET /version', 401, 'missing_authorization_header'));

        $this->assertSame(SearchHealthReport::DOWN, $report->status);
        $this->assertSame(['Meilisearch needs a key and was not given one (HTTP 401). Set MEILISEARCH_KEY.'], $report->problems);
    }

    #[Test]
    public function a_key_that_is_not_valid_is_refused_at_the_first_index_and_says_so_in_the_servers_words(): void
    {
        // Meilisearch answers a key that is not valid, and one that may not do what was asked, alike: 403, "The provided API key is invalid."
        $fake = FakeMeilisearch::serving()->refusing('GET /version', 403)->refusing('GET /indexes/blogs/stats', 403);

        $report = $this->check($fake);

        $this->assertSame(SearchHealthReport::DOWN, $report->status);
        $this->assertCount(1, $report->problems);
        $this->assertStringContainsString('refused the key (HTTP 403)', $report->problems[0]);
        $this->assertStringContainsString('not a valid key, or it may not do something the app needs', $report->problems[0]);
        $this->assertStringContainsString('MEILISEARCH_KEY', $report->problems[0]);
    }

    #[Test]
    public function a_key_that_may_not_read_an_index_is_down(): void
    {
        $report = $this->check(FakeMeilisearch::serving()->refusing('GET /indexes/blogs/stats', 403));

        $this->assertSame(SearchHealthReport::DOWN, $report->status);
        $this->assertStringContainsString('refused the key (HTTP 403)', $report->problems[0]);
        $this->assertStringContainsString('docs/search-index.md', $report->problems[0]);
    }

    #[Test]
    public function no_message_carries_the_key(): void
    {
        config(['scout.meilisearch.key' => 'secret-key-abc123']);

        foreach ([
            FakeMeilisearch::serving()->unreachable(),
            FakeMeilisearch::serving()->refusing('GET /version', 401),
            FakeMeilisearch::serving()->refusing('GET /indexes/blogs/stats', 403),
            FakeMeilisearch::serving()->refusing('GET /indexes/blogs/stats', 500, 'internal'),
        ] as $fake) {
            $report = (new SearchHealth($fake->client('http://meili.test', 'secret-key-abc123'), new MeilisearchCircuitBreaker))->check();

            $this->assertStringNotContainsString('secret-key-abc123', (string) json_encode($report->toArray()));
        }
    }

    // --- Needs attention -----------------------------------------------------------------------------------------------------------

    #[Test]
    public function a_fresh_install_has_no_index_and_is_told_once_to_reindex(): void
    {
        $fake = FakeMeilisearch::serving();

        foreach (array_keys(FakeMeilisearch::models()) as $name) {
            $fake->refusing("GET /indexes/{$name}/stats", 404, 'index_not_found', 'invalid_request');
        }

        $report = $this->check($fake);

        $this->assertSame(SearchHealthReport::ATTENTION, $report->status);
        $this->assertSame(['No search index exists yet. Run: php artisan metaforge:search:reindex'], $report->problems);
        $this->assertSame([false, false, false, false], array_column($report->indexes, 'exists'));
        $this->assertSame([null, null, null, null], array_column($report->indexes, 'documents'));
    }

    #[Test]
    public function missing_indexes_are_named(): void
    {
        $one = $this->check(FakeMeilisearch::serving()->refusing('GET /indexes/faqs/stats', 404, 'index_not_found', 'invalid_request'));
        $two = $this->check(FakeMeilisearch::serving()->refusing('GET /indexes/faqs/stats', 404, 'index_not_found', 'invalid_request')->refusing('GET /indexes/products/stats', 404, 'index_not_found', 'invalid_request'));

        $this->assertSame(['The [faqs] index does not exist. Run: php artisan metaforge:search:reindex'], $one->problems);
        $this->assertSame(['The [faqs] and [products] indexes do not exist. Run: php artisan metaforge:search:reindex'], $two->problems);
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function differentSettings(): array
    {
        return [
            'other searchable attributes' => ['searchableAttributes', ['body', 'title']],
            'a filterable attribute' => ['filterableAttributes', ['status']],
            'a sortable attribute' => ['sortableAttributes', ['created_at']],
            'other ranking rules' => ['rankingRules', ['words', 'typo']],
            'typo tolerance off' => ['typoTolerance.enabled', false],
            'another one-typo length' => ['typoTolerance.minWordSizeForTypos.oneTypo', 5],
            'another two-typo length' => ['typoTolerance.minWordSizeForTypos.twoTypos', 9],
        ];
    }

    #[Test]
    #[DataProvider('differentSettings')]
    public function settings_that_are_not_the_coded_ones_are_reported_with_the_command_to_run(string $path, mixed $value): void
    {
        $live = FakeMeilisearch::liveSettings(Blog::class);
        data_set($live, $path, $value);

        $report = $this->check(FakeMeilisearch::serving()->on('GET /indexes/blogs/settings', $live));

        $this->assertSame(SearchHealthReport::ATTENTION, $report->status);
        $this->assertSame(['The [blogs] settings are not the ones this app expects. Run: php artisan metaforge:search:sync'], $report->problems);
        $this->assertFalse($report->indexes[0]['settings_in_sync']);
        $this->assertSame([true, true, true], array_column(array_slice($report->indexes, 1), 'settings_in_sync'), 'the others are as coded');
    }

    #[Test]
    public function what_meilisearch_adds_to_the_settings_is_not_a_difference(): void
    {
        $live = FakeMeilisearch::liveSettings(ForumThread::class);
        $live['stopWords'] = ['the'];
        $live['synonyms'] = ['hi' => ['hello']];
        $live['faceting'] = ['maxValuesPerFacet' => 50];
        $live['typoTolerance']['disableOnWords'] = ['wow'];

        $report = $this->check(FakeMeilisearch::serving()->on('GET /indexes/forum_threads/settings', $live));

        $this->assertSame(SearchHealthReport::HEALTHY, $report->status);
    }

    #[Test]
    public function a_document_count_that_is_off_is_reported_with_the_command_to_run(): void
    {
        Blog::factory()->published()->count(2)->create();
        $category = FaqCategory::factory()->create();
        Faq::create(['faq_category_id' => $category->id, 'question' => 'Is it open?', 'answer' => 'Yes.', 'order' => 1, 'published' => true]);

        $report = $this->check(FakeMeilisearch::serving(['blogs' => 1, 'faqs' => 0]));

        $this->assertSame(SearchHealthReport::ATTENTION, $report->status);
        $this->assertSame([
            'The [blogs] index has 1 document and 2 should be there. Run: php artisan metaforge:search:reindex',
            'The [faqs] index has 0 documents and 1 should be there. Run: php artisan metaforge:search:reindex',
        ], $report->problems);
    }

    #[Test]
    public function more_documents_than_there_should_be_is_off_too(): void
    {
        $report = $this->check(FakeMeilisearch::serving(['products' => 3]));

        $this->assertSame(['The [products] index has 3 documents and 0 should be there. Run: php artisan metaforge:search:reindex'], $report->problems);
    }

    #[Test]
    public function an_index_that_is_still_updating_is_a_note_and_its_count_is_not_judged(): void
    {
        Blog::factory()->published()->count(2)->create();
        $fake = FakeMeilisearch::serving(['blogs' => 1])->on('GET /indexes/blogs/stats', ['numberOfDocuments' => 1, 'isIndexing' => true, 'fieldDistribution' => []]);

        $report = $this->check($fake);

        $this->assertSame(SearchHealthReport::HEALTHY, $report->status);
        $this->assertSame([], $report->problems);
        $this->assertSame(['The [blogs] index is still being updated, so its count may change.'], $report->notes);
        $this->assertTrue($report->indexes[0]['indexing']);
    }

    #[Test]
    public function an_index_that_cannot_be_read_for_another_reason_is_named_and_the_rest_are_still_checked(): void
    {
        $report = $this->check(FakeMeilisearch::serving()->refusing('GET /indexes/faqs/stats', 500, 'internal', 'internal'));

        $this->assertSame(SearchHealthReport::ATTENTION, $report->status);
        $this->assertCount(1, $report->problems);
        $this->assertStringStartsWith('The [faqs] index could not be read', $report->problems[0]);
        $this->assertSame(['blogs', 'forum_threads', 'products'], array_column($report->indexes, 'name'));
    }

    // --- Updates Meilisearch refused -------------------------------------------------------------------------------------------------

    private function failedTasks(int $total, string $message = 'Document does not have a primary key'): array
    {
        return [
            'results' => [['uid' => 7, 'indexUid' => 'blogs', 'status' => 'failed', 'type' => 'documentAdditionOrUpdate', 'error' => ['message' => $message, 'code' => 'x', 'type' => 'invalid_request', 'link' => 'https://docs.meilisearch.com/errors']]],
            'total' => $total,
            'limit' => 1,
            'from' => 7,
            'next' => null,
        ];
    }

    #[Test]
    public function updates_that_meilisearch_refused_are_counted_and_the_first_reason_is_given(): void
    {
        $fake = FakeMeilisearch::serving()->on('GET /tasks', $this->failedTasks(3));

        $report = $this->check($fake);

        $this->assertSame(SearchHealthReport::ATTENTION, $report->status);
        $this->assertSame(3, $report->failedTasks);
        $this->assertSame(['Meilisearch refused 3 updates so far: Document does not have a primary key'], $report->problems);
    }

    #[Test]
    public function a_single_refused_update_is_said_in_the_singular(): void
    {
        $report = $this->check(FakeMeilisearch::serving()->on('GET /tasks', $this->failedTasks(1)));

        $this->assertSame(['Meilisearch refused 1 update so far: Document does not have a primary key'], $report->problems);
    }

    #[Test]
    public function the_tasks_asked_about_are_the_failed_ones_of_these_indexes_since_the_last_rebuild(): void
    {
        $this->freezeTime();
        $before = FakeMeilisearch::serving();
        $this->check($before);

        $tasks = array_values(array_filter($before->requests, fn (string $request) => str_starts_with($request, 'GET /tasks')));
        $this->assertCount(1, $tasks);
        $this->assertStringContainsString('statuses=failed', $tasks[0]);
        $this->assertStringContainsString('indexUids=blogs,forum_threads,faqs,products', $tasks[0]);
        $this->assertStringContainsString('limit=1', $tasks[0]);
        $this->assertStringNotContainsString('afterEnqueuedAt', $tasks[0], 'with no rebuild on record, every refusal counts');

        SearchIndexState::recordReindex();
        $after = FakeMeilisearch::serving()->on('GET /tasks', $this->failedTasks(2));
        $report = $this->check($after);

        $this->assertTrue($after->asked('GET /tasks?'));
        $this->assertStringContainsString('afterEnqueuedAt='.now()->toIso8601String(), implode(' ', $after->requests));
        $this->assertSame(['Meilisearch refused 2 updates since the last rebuild: Document does not have a primary key'], $report->problems);
    }

    // --- The circuit and the record -----------------------------------------------------------------------------------------------

    #[Test]
    public function an_open_circuit_is_a_note_and_not_a_problem(): void
    {
        (new MeilisearchCircuitBreaker)->open();

        $report = $this->check(FakeMeilisearch::serving());

        $this->assertSame(SearchHealthReport::HEALTHY, $report->status);
        $this->assertTrue($report->circuitOpen);
        $this->assertCount(1, $report->notes);
        $this->assertStringContainsString('on the database for a short while', $report->notes[0]);
    }

    #[Test]
    public function an_open_circuit_is_shown_when_meilisearch_is_down_too(): void
    {
        (new MeilisearchCircuitBreaker)->open();

        $report = $this->check(FakeMeilisearch::serving()->unreachable());

        $this->assertSame(SearchHealthReport::DOWN, $report->status);
        $this->assertTrue($report->circuitOpen);
    }

    #[Test]
    public function indexing_while_a_post_is_saved_is_a_note_that_says_what_an_outage_does(): void
    {
        config(['scout.queue' => false]);

        $report = $this->check(FakeMeilisearch::serving());

        $this->assertSame(SearchHealthReport::HEALTHY, $report->status, 'worth knowing, not wrong');
        $this->assertSame(['Posts are indexed while they are saved (SCOUT_QUEUE=false), so saving one while Meilisearch is down fails. Set SCOUT_QUEUE=true to index on the queue.'], $report->notes);
    }

    #[Test]
    public function indexing_on_the_queue_is_not_mentioned(): void
    {
        $this->assertSame([], $this->check(FakeMeilisearch::serving())->notes);
    }

    #[Test]
    public function a_closed_circuit_is_not_mentioned(): void
    {
        $report = $this->check(FakeMeilisearch::serving());

        $this->assertFalse($report->circuitOpen);
        $this->assertSame([], $report->notes);
    }

    #[Test]
    public function it_says_when_the_settings_were_pushed_and_the_documents_rebuilt(): void
    {
        $this->freezeTime();

        $none = $this->check(FakeMeilisearch::serving());
        $this->assertNull($none->lastSyncedAt);
        $this->assertNull($none->lastReindexedAt);

        SearchIndexState::recordSync();
        $this->travel(2)->hours();
        SearchIndexState::recordReindex();

        $report = $this->check(FakeMeilisearch::serving());
        $this->assertSame(now()->subHours(2)->toIso8601String(), $report->lastSyncedAt);
        $this->assertSame(now()->toIso8601String(), $report->lastReindexedAt);

        $down = $this->check(FakeMeilisearch::serving()->unreachable());
        $this->assertSame(now()->toIso8601String(), $down->lastReindexedAt, 'what was last done is known even when Meilisearch is not answering');
    }

    #[Test]
    public function the_report_is_a_plain_array_for_the_page_and_for_json(): void
    {
        $array = $this->check(FakeMeilisearch::serving())->toArray();

        $this->assertSame(
            ['status', 'driver', 'host', 'version', 'circuit_open', 'last_synced_at', 'last_reindexed_at', 'failed_tasks', 'indexes', 'problems', 'notes'],
            array_keys($array),
        );
        $this->assertSame(['name', 'exists', 'documents', 'expected', 'indexing', 'settings_in_sync'], array_keys($array['indexes'][0]));
    }
}
