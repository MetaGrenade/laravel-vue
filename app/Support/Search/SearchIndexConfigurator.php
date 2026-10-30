<?php

namespace App\Support\Search;

use Laravel\Scout\Contracts\UpdatesIndexSettings;
use Laravel\Scout\EngineManager;
use RuntimeException;

/**
 * Pushes {@see SearchIndexSettings} to the Meilisearch engine. Shared by
 * sync and reindex so the two commands do not drift.
 */
final class SearchIndexConfigurator
{
    public function __construct(
        private readonly EngineManager $engines,
    ) {}

    public function syncSettings(): void
    {
        $engine = $this->engines->engine('meilisearch');

        if (! $engine instanceof UpdatesIndexSettings) {
            throw new RuntimeException('The Meilisearch engine does not support updating index settings.');
        }

        foreach (SearchIndexSettings::forScout() as $modelClass => $settings) {
            $index = (new $modelClass)->indexableAs();
            $engine->updateIndexSettings($index, $settings);
        }

        SearchIndexState::recordSync();
    }

    /**
     * @return list<string>
     */
    public function indexNames(): array
    {
        $names = [];

        foreach (array_keys(SearchIndexSettings::forScout()) as $modelClass) {
            $names[] = (new $modelClass)->indexableAs();
        }

        return $names;
    }
}
