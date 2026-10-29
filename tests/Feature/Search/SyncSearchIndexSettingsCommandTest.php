<?php

namespace Tests\Feature\Search;

use App\Support\Search\SearchIndexSettings;
use Laravel\Scout\Contracts\UpdatesIndexSettings;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\NullEngine;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SyncSearchIndexSettingsCommandTest extends TestCase
{
    #[Test]
    public function it_refuses_to_sync_when_the_driver_is_the_database(): void
    {
        $this->assertSame('database', config('search.driver'));

        $this->artisan('metaforge:search:sync')
            ->expectsOutputToContain('Set SEARCH_DRIVER=meilisearch')
            ->assertFailed();
    }

    #[Test]
    public function it_pushes_settings_for_every_index_when_meilisearch_is_configured(): void
    {
        $engine = new RecordingSettingsEngine;
        $this->app->make(EngineManager::class)->extend('meilisearch', fn () => $engine);

        config([
            'search.driver' => 'meilisearch',
            'scout.driver' => 'meilisearch',
            'scout.prefix' => '',
        ]);

        $this->artisan('metaforge:search:sync')
            ->expectsOutputToContain('[blogs]')
            ->expectsOutputToContain('[forum_threads]')
            ->expectsOutputToContain('[faqs]')
            ->expectsOutputToContain('[products]')
            ->assertSuccessful();

        $this->assertSame(
            array_keys(SearchIndexSettings::forScout()),
            array_keys($engine->updates),
        );

        foreach (SearchIndexSettings::forScout() as $name => $settings) {
            $this->assertSame($settings, $engine->updates[$name]);
        }
    }

    #[Test]
    public function it_applies_the_scout_prefix_to_index_names(): void
    {
        $engine = new RecordingSettingsEngine;
        $this->app->make(EngineManager::class)->extend('meilisearch', fn () => $engine);

        config([
            'search.driver' => 'meilisearch',
            'scout.driver' => 'meilisearch',
            'scout.prefix' => 'site_',
        ]);

        $this->artisan('metaforge:search:sync')->assertSuccessful();

        $this->assertSame(
            ['site_blogs', 'site_forum_threads', 'site_faqs', 'site_products'],
            array_keys($engine->updates),
        );
    }
}

class RecordingSettingsEngine extends NullEngine implements UpdatesIndexSettings
{
    /** @var array<string, array<string, mixed>> */
    public array $updates = [];

    public function updateIndexSettings($name, array $settings = []): void
    {
        $this->updates[$name] = $settings;
    }

    public function configureSoftDeleteFilter(array $settings = []): array
    {
        return $settings;
    }
}
