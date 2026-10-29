<?php

namespace App\Console\Commands;

use App\Support\Search\SearchDriver;
use App\Support\Search\SearchIndexConfigurator;
use App\Support\Search\SearchIndexSettings;
use Illuminate\Console\Command;
use Throwable;

/**
 * Flushes and re-imports every searchable document. Settings are applied
 * first unless skipped. Does not swap indexes; the search page still uses
 * the database.
 */
class ReindexSearchCommand extends Command
{
    protected $signature = 'metaforge:search:reindex
        {--chunk= : The number of records to import at a time}
        {--skip-sync : Skip pushing index settings before importing}';

    protected $description = 'Rebuild Meilisearch documents for blogs, forum threads, FAQs and products';

    public function handle(SearchIndexConfigurator $configurator): int
    {
        if (! SearchDriver::usesMeilisearch()) {
            $this->components->error(SearchDriver::meilisearchRequiredMessage());

            return self::FAILURE;
        }

        try {
            if (! $this->option('skip-sync')) {
                $configurator->syncSettings();
                $this->components->info('Index settings synced.');
            }

            $chunk = $this->option('chunk');
            $chunk = $chunk === null || $chunk === '' ? null : max(1, (int) $chunk);

            foreach (array_keys(SearchIndexSettings::forScout()) as $modelClass) {
                $index = (new $modelClass)->indexableAs();

                $modelClass::removeAllFromSearch();
                $modelClass::makeAllSearchable($chunk);

                $this->components->info("Rebuilt the [{$index}] index.");
            }
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
