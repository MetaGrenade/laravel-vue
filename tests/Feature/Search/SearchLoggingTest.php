<?php

namespace Tests\Feature\Search;

use App\Models\SearchQuery;
use App\Support\Search\GlobalSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Mockery;
use Tests\TestCase;

class SearchLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function tearDown(): void
    {
        parent::tearDown();

        Mockery::close();
    }

    public function test_logs_search_queries_with_result_counts(): void
    {
        Config::set('search.queries.minimum_length', 2);

        $payload = [
            'query' => 'laravel',
            'results' => [
                'blogs' => [
                    'items' => [
                        ['id' => 1],
                        ['id' => 2],
                    ],
                    'meta' => [
                        'total' => 4,
                        'current_page' => 1,
                        'last_page' => 1,
                    ],
                ],
                'forum_threads' => [
                    'items' => [],
                    'meta' => [
                        'total' => 2,
                        'current_page' => 1,
                        'last_page' => 1,
                    ],
                ],
                'faqs' => [
                    'items' => [],
                    'meta' => [
                        'total' => 1,
                        'current_page' => 1,
                        'last_page' => 1,
                    ],
                ],
            ],
        ];

        $mock = Mockery::mock(GlobalSearchService::class);
        $mock->shouldReceive('search')
            ->once()
            ->with('Laravel', Mockery::on(fn ($options) => is_array($options)))
            ->andReturn($payload);

        $this->app->instance(GlobalSearchService::class, $mock);

        $response = $this->getJson(route('search', ['q' => 'Laravel', 'limit' => 5]));

        $response->assertOk();
        $response->assertJsonPath('query', 'laravel');
        $response->assertJsonPath('results.blogs.items.0.id', 1);

        $this->assertDatabaseHas('search_queries', [
            'term' => 'laravel',
            'result_count' => 7,
        ]);
    }

    public function test_logs_zero_result_searches(): void
    {
        Config::set('search.queries.minimum_length', 2);

        $payload = [
            'query' => 'unknown',
            'results' => [
                'blogs' => [
                    'items' => [],
                    'meta' => [
                        'total' => 0,
                        'current_page' => 1,
                        'last_page' => 1,
                    ],
                ],
                'forum_threads' => [
                    'items' => [],
                    'meta' => [
                        'total' => 0,
                        'current_page' => 1,
                        'last_page' => 1,
                    ],
                ],
                'faqs' => [
                    'items' => [],
                    'meta' => [
                        'total' => 0,
                        'current_page' => 1,
                        'last_page' => 1,
                    ],
                ],
            ],
        ];

        $mock = Mockery::mock(GlobalSearchService::class);
        $mock->shouldReceive('search')
            ->once()
            ->andReturn($payload);

        $this->app->instance(GlobalSearchService::class, $mock);

        $this->getJson(route('search', ['q' => 'Unknown']));

        $this->assertDatabaseHas('search_queries', [
            'term' => 'unknown',
            'result_count' => 0,
        ]);
    }

    public function test_skips_logging_for_short_queries(): void
    {
        Config::set('search.queries.minimum_length', 3);

        $payload = [
            'query' => 'ab',
            'results' => [
                'blogs' => [
                    'items' => [],
                    'meta' => [
                        'total' => 0,
                        'current_page' => 1,
                        'last_page' => 1,
                    ],
                ],
                'forum_threads' => [
                    'items' => [],
                    'meta' => [
                        'total' => 0,
                        'current_page' => 1,
                        'last_page' => 1,
                    ],
                ],
                'faqs' => [
                    'items' => [],
                    'meta' => [
                        'total' => 0,
                        'current_page' => 1,
                        'last_page' => 1,
                    ],
                ],
            ],
        ];

        $mock = Mockery::mock(GlobalSearchService::class);
        $mock->shouldReceive('search')
            ->once()
            ->andReturn($payload);

        $this->app->instance(GlobalSearchService::class, $mock);

        $this->getJson(route('search', ['q' => 'ab']));

        $this->assertDatabaseCount('search_queries', 0);
    }
}
