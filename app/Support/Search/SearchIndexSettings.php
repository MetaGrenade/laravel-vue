<?php

namespace App\Support\Search;

use App\Models\Blog;
use App\Models\Faq;
use App\Models\ForumThread;
use App\Models\Product;

/**
 * Meilisearch index settings for the four search indexes. Applied by
 * `php artisan metaforge:search:sync`. Document import is a later command.
 *
 * Keys are model classes so Scout resolves the index name through
 * `indexableAs()` / `searchableAs()`, including `scout.prefix`.
 */
final class SearchIndexSettings
{
    /**
     * Settings keyed by searchable model class.
     *
     * @return array<class-string, array<string, mixed>>
     */
    public static function forScout(): array
    {
        return [
            Blog::class => self::index(
                searchable: ['title', 'excerpt', 'body', 'author'],
            ),
            ForumThread::class => self::index(
                searchable: ['title', 'excerpt', 'replies', 'author'],
            ),
            Faq::class => self::index(
                searchable: ['question', 'answer', 'category'],
            ),
            Product::class => self::index(
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
