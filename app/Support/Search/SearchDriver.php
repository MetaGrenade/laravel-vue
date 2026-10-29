<?php

namespace App\Support\Search;

/**
 * Whether Meilisearch artisan commands may run. Both SEARCH_DRIVER and
 * Scout's resolved driver must be meilisearch.
 */
final class SearchDriver
{
    public static function usesMeilisearch(): bool
    {
        return config('search.driver') === 'meilisearch'
            && config('scout.driver') === 'meilisearch';
    }

    public static function meilisearchRequiredMessage(): string
    {
        return 'Set SEARCH_DRIVER=meilisearch to use the Meilisearch search commands.';
    }
}
