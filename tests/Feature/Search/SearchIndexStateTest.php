<?php

namespace Tests\Feature\Search;

use App\Models\Blog;
use App\Models\SystemSetting;
use App\Support\Search\SearchIndexState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Scout\Contracts\UpdatesIndexSettings;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\CollectionEngine;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakesSearchTasks;
use Tests\TestCase;

/**
 * When the settings were last pushed and the documents last rebuilt (docs/search-index.md): written by the two commands once they have
 * done their work, and not by anything else.
 */
class SearchIndexStateTest extends TestCase
{
    use FakesSearchTasks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['search.driver' => 'meilisearch', 'scout.driver' => 'meilisearch', 'scout.prefix' => '']);
        $this->app->make(EngineManager::class)->extend('meilisearch', fn () => new TakesSettingsEngine);
        // The rebuild asks Meilisearch about its tasks; this one has none.
        $this->fakeSearchTasks();
    }

    #[Test]
    public function nothing_is_recorded_before_a_command_has_run(): void
    {
        $this->assertNull(SearchIndexState::lastSyncedAt());
        $this->assertNull(SearchIndexState::lastReindexedAt());
        $this->assertNull(SearchIndexState::lastReindexFromTask());
    }

    #[Test]
    public function a_sync_notes_when_the_settings_were_pushed_and_nothing_else(): void
    {
        $this->freezeTime();

        $this->artisan('metaforge:search:sync')->assertSuccessful();

        $this->assertSame(now()->toIso8601String(), SearchIndexState::lastSyncedAt()->toIso8601String());
        $this->assertNull(SearchIndexState::lastReindexedAt());
        $this->assertNull(SearchIndexState::lastReindexFromTask(), 'a sync begins no rebuild');
    }

    #[Test]
    public function a_reindex_notes_both_because_it_pushes_the_settings_first(): void
    {
        $this->freezeTime();
        Blog::factory()->published()->create();

        $this->artisan('metaforge:search:reindex')->assertSuccessful();

        $this->assertSame(now()->toIso8601String(), SearchIndexState::lastSyncedAt()->toIso8601String());
        $this->assertSame(now()->toIso8601String(), SearchIndexState::lastReindexedAt()->toIso8601String());
        $this->assertSame(0, SearchIndexState::lastReindexFromTask(), 'and where it began: the first task there will be');
    }

    #[Test]
    public function a_reindex_that_skips_the_settings_notes_only_the_rebuild(): void
    {
        $this->artisan('metaforge:search:reindex', ['--skip-sync' => true])->assertSuccessful();

        $this->assertNull(SearchIndexState::lastSyncedAt());
        $this->assertNotNull(SearchIndexState::lastReindexedAt());
        $this->assertSame(0, SearchIndexState::lastReindexFromTask());
    }

    #[Test]
    public function a_command_that_refuses_notes_nothing(): void
    {
        config(['search.driver' => 'database', 'scout.driver' => 'null']);

        $this->artisan('metaforge:search:sync')->assertFailed();
        $this->artisan('metaforge:search:reindex')->assertFailed();

        $this->assertNull(SearchIndexState::lastSyncedAt());
        $this->assertNull(SearchIndexState::lastReindexedAt());
        $this->assertNull(SearchIndexState::lastReindexFromTask(), 'it refused before it began');
    }

    #[Test]
    public function a_command_that_fails_part_way_notes_nothing(): void
    {
        $this->app->make(EngineManager::class)->extend('meilisearch', fn () => new TakesSettingsEngine(failing: true));

        $this->artisan('metaforge:search:sync')->assertFailed();
        $this->artisan('metaforge:search:reindex')->assertFailed();

        $this->assertNull(SearchIndexState::lastSyncedAt());
        $this->assertNull(SearchIndexState::lastReindexedAt(), 'it was not done');
        $this->assertSame(0, SearchIndexState::lastReindexFromTask(), 'but it had begun, and what it sent before it failed is counted from there');
    }

    #[Test]
    public function the_note_is_kept_as_a_system_setting_and_read_back_as_a_time(): void
    {
        $this->freezeTime();

        SearchIndexState::recordSync();

        $this->assertSame(now()->toIso8601String(), SystemSetting::get('search.settings_synced_at'));

        SystemSetting::set('search.reindexed_at', '');
        $this->assertNull(SearchIndexState::lastReindexedAt(), 'an empty note is no note');

        SearchIndexState::recordReindexBegan(41);
        $this->assertSame(41, SystemSetting::get('search.reindex_from_task'));
        $this->assertSame(41, SearchIndexState::lastReindexFromTask());

        SearchIndexState::recordReindexBegan(0);
        $this->assertSame(0, SearchIndexState::lastReindexFromTask(), 'the first task there is, is a number too');

        SystemSetting::set('search.reindex_from_task', 'not a number');
        $this->assertNull(SearchIndexState::lastReindexFromTask());
        SystemSetting::set('search.reindex_from_task', -3);
        $this->assertNull(SearchIndexState::lastReindexFromTask());
    }
}

/**
 * Takes the settings and the documents without a server, and can refuse the settings.
 */
class TakesSettingsEngine extends CollectionEngine implements UpdatesIndexSettings
{
    public function __construct(private readonly bool $failing = false) {}

    public function updateIndexSettings($name, array $settings = []): void
    {
        if ($this->failing) {
            throw new \RuntimeException('Meilisearch refused the settings.');
        }
    }

    public function configureSoftDeleteFilter(array $settings = []): array
    {
        return $settings;
    }
}
