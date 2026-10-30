<?php

namespace Tests\Feature\Search;

use App\Models\Blog;
use App\Support\Search\MeilisearchCircuitBreaker;
use App\Support\Search\SearchHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\CollectionEngine;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeMeilisearch;
use Tests\TestCase;

/**
 * `php artisan metaforge:search:health` (docs/search-index.md): what it prints, and that it fails when something is wrong so a deploy
 * script can stop on it.
 */
class SearchHealthCommandTest extends TestCase
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
            'scout.queue' => true,
        ]);

        $this->app->make(EngineManager::class)->extend('meilisearch', fn () => new CollectionEngine);
    }

    private function using(FakeMeilisearch $fake): void
    {
        $this->app->instance(SearchHealth::class, new SearchHealth($fake->client(), new MeilisearchCircuitBreaker));
    }

    /**
     * @return array{int, string}
     */
    private function run_(string ...$options): array
    {
        $code = Artisan::call('metaforge:search:health', array_fill_keys($options, true));

        return [$code, Artisan::output()];
    }

    #[Test]
    public function it_says_nothing_is_in_use_when_search_uses_the_database(): void
    {
        config(['search.driver' => 'database', 'scout.driver' => 'null']);

        $this->artisan('metaforge:search:health')
            ->expectsOutputToContain('Search uses the database (SEARCH_DRIVER=database)')
            ->assertSuccessful();
    }

    #[Test]
    public function a_healthy_meilisearch_is_described_and_the_command_succeeds(): void
    {
        $this->using(FakeMeilisearch::serving());

        [$code, $output] = $this->run_();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('http://meili.test', $output);
        $this->assertStringContainsString('1.12.8', $output);
        $this->assertStringContainsString('never recorded', $output);
        $this->assertStringContainsString('as coded', $output);
        $this->assertStringContainsString('Meilisearch is healthy.', $output);
    }

    #[Test]
    public function a_meilisearch_that_needs_attention_fails_the_command_and_says_what_to_run(): void
    {
        Blog::factory()->published()->count(2)->create();
        $this->using(FakeMeilisearch::serving());

        [$code, $output] = $this->run_();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('The [blogs] index has 0 documents and 2 should be there. Run: php artisan metaforge:search:reindex', $output);
        $this->assertStringNotContainsString('Meilisearch is healthy.', $output);
    }

    #[Test]
    public function missing_indexes_are_shown_as_missing_in_the_table(): void
    {
        $fake = FakeMeilisearch::serving()->refusing('GET /indexes/blogs/stats', 404, 'index_not_found', 'invalid_request');
        $this->using($fake);

        [$code, $output] = $this->run_();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('missing', $output);
        $this->assertStringContainsString('The [blogs] index does not exist.', $output);
    }

    #[Test]
    public function a_meilisearch_that_cannot_be_reached_fails_the_command(): void
    {
        $this->using(FakeMeilisearch::serving()->unreachable());

        [$code, $output] = $this->run_();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('cannot be reached at http://meili.test', $output);
    }

    #[Test]
    public function notes_are_printed_and_do_not_fail_the_command(): void
    {
        $this->using(FakeMeilisearch::serving()->on('GET /indexes/faqs/stats', ['numberOfDocuments' => 0, 'isIndexing' => true]));

        [$code, $output] = $this->run_();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('The [faqs] index is still being updated', $output);
    }

    #[Test]
    public function the_json_report_is_the_same_report_and_carries_the_same_exit_code(): void
    {
        $this->using(FakeMeilisearch::serving());
        [$code, $output] = $this->run_('--json');

        $report = json_decode($output, true);
        $this->assertSame(0, $code);
        $this->assertSame('healthy', $report['status']);
        $this->assertSame('http://meili.test', $report['host']);
        $this->assertSame(['blogs', 'forum_threads', 'faqs', 'products'], array_column($report['indexes'], 'name'));

        $this->using(FakeMeilisearch::serving()->unreachable());
        [$code, $output] = $this->run_('--json');

        $this->assertSame(1, $code);
        $this->assertSame('down', json_decode($output, true)['status']);
    }

    #[Test]
    public function the_json_report_for_the_database_driver_is_a_success(): void
    {
        config(['search.driver' => 'database', 'scout.driver' => 'null']);

        [$code, $output] = $this->run_('--json');

        $this->assertSame(0, $code);
        $this->assertSame('unused', json_decode($output, true)['status']);
    }
}
