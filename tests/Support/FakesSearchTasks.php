<?php

namespace Tests\Support;

use App\Support\Search\SearchTasks;

/**
 * For a test that runs `metaforge:search:reindex` with an engine that is not a Meilisearch: the rebuild asks Meilisearch what its tasks
 * are, so there has to be one to ask (docs/search-index.md). It answers as an idle Meilisearch with no tasks, unless the test says more.
 */
trait FakesSearchTasks
{
    protected function fakeSearchTasks(?FakeMeilisearch $fake = null): FakeMeilisearch
    {
        $fake ??= FakeMeilisearch::serving();
        $this->app->instance(SearchTasks::class, new SearchTasks($fake->client()));

        return $fake;
    }
}
