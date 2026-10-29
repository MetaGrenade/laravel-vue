<?php

namespace App\Console\Commands;

use App\Support\Search\SearchIndexSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Laravel\Scout\Contracts\UpdatesIndexSettings;
use Laravel\Scout\EngineManager;
use Throwable;

/**
 * Pushes the coded Meilisearch index settings to the server so a fresh
 * install configures itself. Does not import documents.
 */
class SyncSearchIndexSettingsCommand extends Command
{
    protected $signature = 'metaforge:search:sync';

    protected $description = 'Apply Meilisearch index settings for blogs, forum threads, FAQs and products';

    public function handle(EngineManager $engines): int
    {
        if (config('search.driver') !== 'meilisearch' || config('scout.driver') !== 'meilisearch') {
            $this->components->error('Set SEARCH_DRIVER=meilisearch to sync index settings.');

            return self::FAILURE;
        }

        $engine = $engines->engine('meilisearch');

        if (! $engine instanceof UpdatesIndexSettings) {
            $this->components->error('The Meilisearch engine does not support updating index settings.');

            return self::FAILURE;
        }

        $prefix = (string) config('scout.prefix', '');

        try {
            foreach (SearchIndexSettings::forScout() as $name => $settings) {
                $index = ! Str::startsWith($name, $prefix) ? $prefix.$name : $name;
                $engine->updateIndexSettings($index, $settings);
                $this->components->info("Settings for the [{$index}] index synced successfully.");
            }
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
