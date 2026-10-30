<?php

namespace Tests\Feature\Search;

use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Scout\Contracts\UpdatesIndexSettings;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\CollectionEngine;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The note of when the index was updated is a convenience (docs/search-index.md): a database that cannot take it must not fail a command
 * that has done its work. The write is made to fail by a listener on the model, which is the same on every database (an empty database
 * would not do: on MySQL and PostgreSQL the tables of an earlier test are still there).
 */
class SearchIndexStateThatCannotBeKeptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['search.driver' => 'meilisearch', 'scout.driver' => 'meilisearch', 'scout.prefix' => '']);
        $this->app->make(EngineManager::class)->extend('meilisearch', fn () => new TakesSettingsToo);

        // On this test's own application, so it is gone with it.
        SystemSetting::saving(fn () => throw new RuntimeException('The database is not taking writes.'));
    }

    #[Test]
    public function a_note_that_cannot_be_kept_does_not_fail_the_sync_and_is_logged(): void
    {
        Log::spy();

        $this->artisan('metaforge:search:sync')->assertSuccessful();

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_starts_with($message, 'Could not note when the search index was updated: The database is not taking writes.'));
    }

    #[Test]
    public function nor_does_it_fail_a_rebuild(): void
    {
        Log::spy();

        $this->artisan('metaforge:search:reindex')->assertSuccessful();

        // The settings are noted and so is the rebuild: both are logged and neither stops the command.
        Log::shouldHaveReceived('warning')->twice();
    }
}

/**
 * Takes the settings and the documents without a server.
 */
class TakesSettingsToo extends CollectionEngine implements UpdatesIndexSettings
{
    public function updateIndexSettings($name, array $settings = []): void {}

    public function configureSoftDeleteFilter(array $settings = []): array
    {
        return $settings;
    }
}
