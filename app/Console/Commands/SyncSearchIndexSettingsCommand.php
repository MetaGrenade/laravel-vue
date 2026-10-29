<?php

namespace App\Console\Commands;

use App\Support\Search\SearchDriver;
use App\Support\Search\SearchIndexConfigurator;
use Illuminate\Console\Command;
use Throwable;

/**
 * Pushes the coded Meilisearch index settings to the server so a fresh
 * install configures itself. Does not import documents.
 */
class SyncSearchIndexSettingsCommand extends Command
{
    protected $signature = 'metaforge:search:sync';

    protected $description = 'Apply Meilisearch index settings for blogs, forum threads, FAQs and products';

    public function handle(SearchIndexConfigurator $configurator): int
    {
        if (! SearchDriver::usesMeilisearch()) {
            $this->components->error(SearchDriver::meilisearchRequiredMessage());

            return self::FAILURE;
        }

        try {
            $configurator->syncSettings();

            foreach ($configurator->indexNames() as $index) {
                $this->components->info("Settings for the [{$index}] index synced successfully.");
            }
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
