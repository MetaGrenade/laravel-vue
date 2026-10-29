<?php

namespace App\Support\Search;

/**
 * Meilisearch index settings for the four search indexes. Applied by
 * `php artisan metaforge:search:sync`. Document import is a later command.
 */
final class SearchIndexSettings
{
    /**
     * Settings keyed by Scout index name (`searchableAs()` on each model).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function forScout(): array
    {
        return [
            'blogs' => self::index(
                searchable: ['title', 'excerpt', 'body', 'author'],
            ),
            'forum_threads' => self::index(
                searchable: ['title', 'excerpt', 'replies', 'author'],
            ),
            'faqs' => self::index(
                searchable: ['question', 'answer', 'category'],
            ),
            'products' => self::index(
                searchable: ['name', 'description', 'brand'],
            ),
        ];
    }

    /**
     * @param  list<string>  $searchable
     * @return array<string, mixed>
     */
    private static function index(array $searchable): array
    {
        return [
            'searchableAttributes' => $searchable,
            'filterableAttributes' => [],
            'sortableAttributes' => [],
            'rankingRules' => [
                'words',
                'typo',
                'proximity',
                'attribute',
                'sort',
                'exactness',
            ],
            'typoTolerance' => [
                'enabled' => true,
                'minWordSizeForTypos' => [
                    'oneTypo' => 4,
                    'twoTypos' => 8,
                ],
            ],
        ];
    }
}
