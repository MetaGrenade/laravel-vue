<?php

namespace Tests\Feature\Search;

use App\Jobs\AggregateSearchQueryStats;
use App\Jobs\PruneSearchQueryLogs;
use App\Models\SearchQuery;
use App\Models\SearchQueryAggregate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchQueryAggregationTest extends TestCase
{
    use RefreshDatabase;

    public function test_aggregate_job_compiles_stats_within_window(): void
    {
        config()->set('search.queries.aggregation_window_days', 30);

        SearchQuery::factory()->create([
            'term' => 'laravel',
            'result_count' => 5,
            'created_at' => now()->subDays(2),
        ]);

        SearchQuery::factory()->create([
            'term' => 'laravel',
            'result_count' => 0,
            'created_at' => now()->subDays(1),
        ]);

        SearchQuery::factory()->create([
            'term' => 'vue',
            'result_count' => 2,
            'created_at' => now()->subDays(3),
        ]);

        SearchQuery::factory()->create([
            'term' => 'legacy',
            'result_count' => 1,
            'created_at' => now()->subDays(45),
        ]);

        (new AggregateSearchQueryStats())->handle();

        $this->assertDatabaseHas('search_query_aggregates', [
            'term' => 'laravel',
            'total_count' => 2,
            'zero_result_count' => 1,
        ]);

        $this->assertDatabaseHas('search_query_aggregates', [
            'term' => 'vue',
            'total_count' => 1,
            'zero_result_count' => 0,
        ]);

        $this->assertDatabaseMissing('search_query_aggregates', [
            'term' => 'legacy',
        ]);
    }

    public function test_prune_job_removes_old_entries(): void
    {
        config()->set('search.queries.retention_days', 30);

        $recent = SearchQuery::factory()->create([
            'created_at' => now()->subDays(10),
        ]);

        $stale = SearchQuery::factory()->create([
            'created_at' => now()->subDays(45),
        ]);

        (new PruneSearchQueryLogs())->handle();

        $this->assertDatabaseHas('search_queries', ['id' => $recent->id]);
        $this->assertDatabaseMissing('search_queries', ['id' => $stale->id]);
    }

    public function test_aggregate_job_clears_old_records_when_no_data(): void
    {
        SearchQueryAggregate::query()->create([
            'term' => 'stale',
            'total_count' => 5,
            'total_results' => 10,
            'zero_result_count' => 1,
            'last_ran_at' => now()->subDay(),
        ]);

        (new AggregateSearchQueryStats())->handle();

        $this->assertDatabaseCount('search_query_aggregates', 0);
    }
}
