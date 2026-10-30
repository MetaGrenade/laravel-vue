<?php

namespace App\Console\Commands;

use App\Support\Search\SearchDriver;
use App\Support\Search\SearchIndexConfigurator;
use App\Support\Search\SearchIndexSettings;
use App\Support\Search\SearchIndexState;
use App\Support\Search\SearchTasks;
use Illuminate\Console\Command;
use Meilisearch\Exceptions\ApiException;
use Throwable;

/**
 * Flushes and re-imports every searchable document, then waits for Meilisearch to do what it was sent and says whether it did.
 * Settings are applied first unless skipped. Does not swap indexes.
 *
 * Meilisearch answers an update at once and does it afterwards, and may refuse it. So the rebuild notes where it BEGAN (a task number,
 * before it sends anything) and counts as DONE only once every update since then has finished and none was refused: the health check
 * counts refusals from that same point (docs/search-index.md).
 */
class ReindexSearchCommand extends Command
{
    protected $signature = 'metaforge:search:reindex
        {--chunk= : The number of records to import at a time}
        {--skip-sync : Skip pushing index settings before importing}
        {--wait= : Seconds to wait for Meilisearch to finish before the rebuild counts as done (0 does not wait; default SEARCH_REINDEX_WAIT)}';

    protected $description = 'Rebuild Meilisearch documents for blogs, forum threads, FAQs and products';

    public function handle(SearchIndexConfigurator $configurator, SearchTasks $tasks): int
    {
        if (! SearchDriver::usesMeilisearch()) {
            $this->components->error(SearchDriver::meilisearchRequiredMessage());

            return self::FAILURE;
        }

        $wait = $this->option('wait');
        $seconds = $wait === null || $wait === '' ? (int) config('search.reindex.wait_seconds', 120) : max(0, (int) $wait);

        try {
            $from = $this->beginning($tasks, $seconds);

            if ($from === false) {
                return self::FAILURE;
            }

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

            if ($seconds === 0) {
                $this->components->warn('Not waiting for Meilisearch, so the rebuild is not recorded as done. Run php artisan metaforge:search:health to see how it goes.');

                return self::SUCCESS;
            }

            return $this->finish($tasks, $configurator->indexNames(), $from, $seconds);
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Note where this rebuild begins, before it sends anything: the task number its first update will have. Returns it, null when it
     * cannot be had and the rebuild is not going to wait anyway, or false when it cannot be had and the rebuild needs it.
     */
    private function beginning(SearchTasks $tasks, int $seconds): int|false|null
    {
        try {
            $from = $tasks->nextUid();
        } catch (ApiException $exception) {
            if ($seconds === 0) {
                return null;
            }

            if (in_array((int) $exception->httpStatus, [401, 403], true)) {
                $this->components->error('The key may not read Meilisearch\'s tasks (the tasks.get action), which a rebuild needs to know that Meilisearch did what it was sent. Give the key that action (see "Keys" in docs/search-index.md), or run again with --wait=0 to rebuild without waiting.');

                return false;
            }

            throw $exception;
        }

        SearchIndexState::recordReindexBegan($from);

        return $from;
    }

    /**
     * Wait for what the rebuild sent, and count it as done only if all of it was accepted.
     *
     * @param  list<string>  $indexes
     */
    private function finish(SearchTasks $tasks, array $indexes, ?int $from, int $seconds): int
    {
        $this->components->info("Waiting for Meilisearch to finish (up to {$seconds} seconds)...");

        if (! $tasks->settle($indexes, $seconds)) {
            $this->components->error("Meilisearch had not finished after {$seconds} seconds, so the rebuild is not recorded as done. It carries on: run php artisan metaforge:search:health to see when it has, and whether it refused anything. Use --wait to give a large index longer.");

            return self::FAILURE;
        }

        $refused = $tasks->refusedFrom($indexes, $from);

        if ($refused->any()) {
            $this->components->error($refused->sentence('in this rebuild').' The rebuild is not recorded as done.');

            return self::FAILURE;
        }

        SearchIndexState::recordReindex();
        $this->components->info('Meilisearch finished the rebuild and refused nothing.');

        return self::SUCCESS;
    }
}
