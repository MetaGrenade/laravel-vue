<?php

namespace App\Support\Search;

/**
 * Whether Meilisearch is in use for search queries and artisan commands.
 * Both SEARCH_DRIVER and Scout's resolved driver must be meilisearch.
 */
final class SearchDriver
{
    public static function usesMeilisearch(): bool
    {
        return config('search.driver') === 'meilisearch'
            && config('scout.driver') === 'meilisearch';
    }

    /**
     * Whether Scout is on an engine that keeps documents. On the null engine (the database search) there is nothing to remove or to
     * rebuild, so whoever would ask for it can skip the work, and the queries that find what to ask for.
     */
    public static function keepsAnIndex(): bool
    {
        $driver = config('scout.driver');

        return $driver !== 'null' && $driver !== null && $driver !== '';
    }

    /**
     * Whether saving a record indexes it on the queue (config/scout.php). SCOUT_QUEUE decides when it is set; when it is not, Meilisearch
     * is indexed on the queue, so that a save still works while Meilisearch is down, and the database search has nothing to index.
     */
    public static function indexesOnQueue(mixed $setting, mixed $driver): bool
    {
        if ($setting === null || $setting === '') {
            return $driver === 'meilisearch';
        }

        return filter_var($setting, FILTER_VALIDATE_BOOLEAN);
    }

    public static function meilisearchRequiredMessage(): string
    {
        return 'Set SEARCH_DRIVER=meilisearch to use the Meilisearch search commands.';
    }
}
