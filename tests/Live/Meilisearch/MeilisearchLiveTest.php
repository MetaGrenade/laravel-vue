<?php

namespace Tests\Live\Meilisearch;

use App\Models\Blog;
use App\Models\Brand;
use App\Models\Faq;
use App\Models\FaqCategory;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\Product;
use App\Support\Search\MeilisearchCircuitBreaker;
use App\Support\Search\SearchHealth;
use App\Support\Search\SearchHealthReport;
use App\Support\Search\SearchIndexSettings;
use App\Support\Search\SearchIndexState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Scout\Jobs\MakeSearchable;
use Meilisearch\Exceptions\CommunicationException;
use PHPUnit\Framework\Attributes\Test;

/**
 * What Meilisearch itself does with the documents and settings this app gives it: what is listed, how it ranks, what a typo still finds,
 * what leaves the index, how the page behaves when the host goes away, and that the health check agrees with all of it
 * (docs/search-index.md).
 */
class MeilisearchLiveTest extends LiveMeilisearchTestCase
{
    private function health(): SearchHealthReport
    {
        return app(SearchHealth::class)->check();
    }

    /**
     * A key the server does not know, made of a repeated word so that it is easy to look for and the secrets scan does not take it for
     * a real one (docs/secrets-scan.md).
     */
    private function wrongKey(): string
    {
        return str_repeat('wrong-', 3).'key';
    }

    /**
     * @return list<string>
     */
    private function titles(string $query): array
    {
        return Blog::search($query)->get()->pluck('title')->all();
    }

    // --- Settings and documents --------------------------------------------------------------------------------------------------

    #[Test]
    public function a_sync_gives_every_index_the_coded_settings(): void
    {
        $this->artisan('metaforge:search:sync')->assertSuccessful();
        $this->settle();

        foreach (SearchIndexSettings::forScout() as $model => $coded) {
            $live = $this->meili->index((new $model)->indexableAs())->getSettings();

            $this->assertSame($coded['searchableAttributes'], $live['searchableAttributes'], $model);
            $this->assertSame($coded['rankingRules'], $live['rankingRules'], $model);
            $this->assertTrue($live['typoTolerance']['enabled'], $model);
            $this->assertSame(4, $live['typoTolerance']['minWordSizeForTypos']['oneTypo'], $model);
            $this->assertSame(8, $live['typoTolerance']['minWordSizeForTypos']['twoTypos'], $model);
        }

        $this->assertNotNull(SearchIndexState::lastSyncedAt());
    }

    #[Test]
    public function a_reindex_lists_only_what_a_guest_could_open_and_the_health_check_agrees(): void
    {
        $author = $this->author();
        Blog::factory()->published()->create(['title' => 'Visible lighthouse', 'user_id' => $author->id]);
        Blog::factory()->create(['title' => 'Draft lighthouse', 'user_id' => $author->id]);
        Blog::factory()->archived()->create(['title' => 'Archived lighthouse', 'user_id' => $author->id]);
        Blog::factory()->scheduled()->create(['title' => 'Future lighthouse', 'user_id' => $author->id]);

        $board = $this->board();
        $this->thread($board, 'Visible thread');
        $this->thread($board, 'Hidden thread', published: false);

        $category = FaqCategory::factory()->create();
        Faq::factory()->create(['faq_category_id' => $category->id, 'question' => 'Visible question?', 'published' => true]);
        Faq::factory()->create(['faq_category_id' => $category->id, 'question' => 'Hidden question?', 'published' => false]);

        $brand = Brand::query()->create(['name' => 'Northwind', 'slug' => 'northwind']);
        Product::factory()->create(['name' => 'Shown lantern', 'brand_id' => $brand->id]);
        Product::factory()->inactive()->create(['name' => 'Hidden lantern']);

        $this->artisan('metaforge:search:reindex')->assertSuccessful();
        $this->settle();

        $this->assertSame(1, $this->documentsIn(Blog::class));
        $this->assertSame(1, $this->documentsIn(ForumThread::class));
        $this->assertSame(1, $this->documentsIn(Faq::class));
        $this->assertSame(1, $this->documentsIn(Product::class));
        $this->assertNotNull(SearchIndexState::lastReindexedAt());

        $report = $this->health();
        $this->assertSame(SearchHealthReport::HEALTHY, $report->status, implode(' ', $report->problems));
        $this->assertSame(array_fill(0, 4, 1), array_column($report->indexes, 'documents'));
        $this->assertSame(array_fill(0, 4, 1), array_column($report->indexes, 'expected'));
        $this->assertNotNull($report->version);

        $this->artisan('metaforge:search:health')->expectsOutputToContain('Meilisearch is healthy.')->assertSuccessful();
    }

    /**
     * Run the rebuild and give its exit code and what it printed in one line (a long message wraps).
     *
     * @param  array<string, mixed>  $options
     * @return array{int, string}
     */
    private function reindex(array $options = []): array
    {
        $code = Artisan::call('metaforge:search:reindex', $options);

        return [$code, trim((string) preg_replace('/\s+/', ' ', Artisan::output()))];
    }

    #[Test]
    public function a_rebuild_that_meilisearch_refuses_fails_and_is_not_recorded_and_health_shows_what_it_refused(): void
    {
        // An index that already has a primary key the documents do not have: Meilisearch takes every update and then refuses it.
        $index = (new Blog)->indexableAs();
        $this->meili->waitForTask($this->meili->createIndex($index, ['primaryKey' => 'nope'])['taskUid']);
        Blog::withoutSyncingToSearch(fn () => Blog::factory()->published()->create(['title' => 'Kubernetes basics', 'user_id' => $this->author()->id]));

        [$code, $output] = $this->reindex();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Meilisearch refused 1 update in this rebuild', $output);
        $this->assertStringContainsString('nope', $output, 'and why, in Meilisearch\'s words');
        $this->assertStringContainsString('The rebuild is not recorded as done.', $output);
        $this->assertNull(SearchIndexState::lastReindexedAt());
        $this->assertNotNull(SearchIndexState::lastReindexFromTask());

        // The refusal came after the command sent the update, and is the rebuild's own: it is counted from where the rebuild began.
        $report = $this->health();
        $this->assertSame(SearchHealthReport::ATTENTION, $report->status);
        $this->assertSame(1, $report->failedTasks);
        $this->assertStringStartsWith('Meilisearch refused 1 update since the last rebuild began', collect($report->problems)->first(fn (string $problem) => str_contains($problem, 'refused')));

        // Put right, the next rebuild is done, and what the last one was refused is behind it.
        $this->meili->waitForTask($this->meili->deleteIndex($index)['taskUid']);
        [$code, $output] = $this->reindex();

        $this->assertSame(0, $code, $output);
        $this->assertNotNull(SearchIndexState::lastReindexedAt());
        $report = $this->health();
        $this->assertSame(SearchHealthReport::HEALTHY, $report->status, implode(' ', $report->problems));
        $this->assertSame(0, $report->failedTasks);
    }

    #[Test]
    public function a_key_that_may_not_read_tasks_cannot_wait_for_a_rebuild_but_can_rebuild_without_waiting(): void
    {
        $host = (string) getenv('MEILISEARCH_LIVE_HOST');
        $master = getenv('MEILISEARCH_LIVE_KEY') ?: null;
        $key = $this->meili->createKey([
            'name' => 'metaforge test without tasks '.$this->prefix,
            'actions' => array_values(array_diff(self::actionsTheAppNeeds(), ['tasks.get'])),
            'indexes' => [$this->prefix.'*'],
            'expiresAt' => null,
        ]);
        Blog::withoutSyncingToSearch(fn () => Blog::factory()->published()->create(['title' => 'Kubernetes basics', 'user_id' => $this->author()->id]));

        try {
            $this->useMeilisearch($host, $key->getKey());

            [$code, $output] = $this->reindex();
            $this->assertSame(1, $code);
            $this->assertStringContainsString('tasks.get', $output);
            $this->assertNull(SearchIndexState::lastReindexFromTask(), 'and nothing was sent');

            [$code, $output] = $this->reindex(['--wait' => 0]);
            $this->assertSame(0, $code, $output);
            $this->assertStringContainsString('Not waiting for Meilisearch', $output);
            $this->assertNull(SearchIndexState::lastReindexedAt(), 'it was not waited for, so it is not recorded as done');
        } finally {
            $this->useMeilisearch($host, $master);
            $this->meili->deleteKey($key->getKey());
        }

        $this->settle();
        $this->assertSame(1, $this->documentsIn(Blog::class), 'but it was sent, and Meilisearch did it');
    }

    // --- How it ranks and what it forgives ---------------------------------------------------------------------------------------

    #[Test]
    public function a_match_in_the_title_ranks_above_a_match_in_the_body(): void
    {
        $this->artisan('metaforge:search:sync')->assertSuccessful();
        $author = $this->author();
        Blog::factory()->published()->create(['title' => 'Cooking pasta', 'excerpt' => 'Italian food', 'body' => 'We mention kubernetes once, deep in the body of this post.', 'user_id' => $author->id]);
        Blog::factory()->published()->create(['title' => 'Kubernetes basics', 'excerpt' => 'An introduction', 'body' => 'About containers.', 'user_id' => $author->id]);
        $this->settle();

        $this->assertSame(['Kubernetes basics', 'Cooking pasta'], $this->titles('kubernetes'), 'the title first, and the body found too');
    }

    #[Test]
    public function a_typo_is_forgiven_in_a_longer_word_and_not_in_a_short_one(): void
    {
        $this->artisan('metaforge:search:sync')->assertSuccessful();
        $author = $this->author();
        Blog::factory()->published()->create(['title' => 'Kubernetes basics', 'user_id' => $author->id]);
        Blog::factory()->published()->create(['title' => 'Cat care', 'user_id' => $author->id]);
        $this->settle();

        $this->assertSame(['Kubernetes basics'], $this->titles('kubernets'), 'one letter missing from a long word');
        $this->assertSame(['Kubernetes basics'], $this->titles('kubrnets'), 'two missing from a word of eight letters or more');
        $this->assertSame(['Cat care'], $this->titles('catt'), 'one wrong in a word of four');
        $this->assertSame([], $this->titles('cot'), 'but none in a word of three');
    }

    // --- What leaves the index ---------------------------------------------------------------------------------------------------

    #[Test]
    public function unpublishing_a_post_takes_it_out_of_the_index_and_publishing_it_puts_it_back(): void
    {
        $this->artisan('metaforge:search:sync')->assertSuccessful();
        $blog = Blog::factory()->published()->create(['title' => 'Lighthouse keeper', 'user_id' => $this->author()->id]);
        $this->settle();
        $this->assertSame(['Lighthouse keeper'], $this->titles('lighthouse'));

        $blog->update(['status' => 'draft', 'published_at' => null]);
        $this->settle();
        $this->assertSame([], $this->titles('lighthouse'));
        $this->assertSame(0, $this->documentsIn(Blog::class));

        $blog->update(['status' => 'published', 'published_at' => now()]);
        $this->settle();
        $this->assertSame(['Lighthouse keeper'], $this->titles('lighthouse'));

        $blog->delete();
        $this->settle();
        $this->assertSame([], $this->titles('lighthouse'));
    }

    #[Test]
    public function a_held_reply_is_not_in_its_threads_document_and_holding_one_takes_its_words_out(): void
    {
        $this->artisan('metaforge:search:sync')->assertSuccessful();
        $thread = $this->thread($this->board(), 'A public thread');
        $visible = ForumPost::query()->create(['forum_thread_id' => $thread->id, 'user_id' => $thread->user_id, 'body' => 'a reply about zephyrword']);
        (new ForumPost)->forceFill(['forum_thread_id' => $thread->id, 'user_id' => $thread->user_id, 'body' => 'a held reply about quarksecret', 'held_at' => now()])->save();
        $this->settle();

        $this->assertCount(1, ForumThread::search('zephyrword')->get());
        $this->assertCount(0, ForumThread::search('quarksecret')->get(), 'a reply that is held never reached the index');

        $visible->forceFill(['held_at' => now()])->save();
        $this->settle();

        $this->assertCount(0, ForumThread::search('zephyrword')->get(), 'and a reply that is held later leaves it');
    }

    // --- The page ----------------------------------------------------------------------------------------------------------------

    #[Test]
    public function the_search_page_reads_the_live_index(): void
    {
        $this->artisan('metaforge:search:sync')->assertSuccessful();
        $author = $this->author();
        $blog = Blog::factory()->published()->create(['title' => 'Kubernetes basics', 'user_id' => $author->id]);
        Blog::factory()->create(['title' => 'Kubernetes draft', 'user_id' => $author->id]);
        $thread = $this->thread($this->board(), 'Kubernetes thread');
        $this->settle();

        // A typo the database search would not forgive, so the answer can only have come from Meilisearch.
        $this->get(route('search.results', ['q' => 'kubernets']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('results.blogs.meta.total', 1)
            ->where('results.blogs.items.0.id', $blog->id)
            ->where('results.forum_threads.meta.total', 1)
            ->where('results.forum_threads.items.0.id', $thread->id));
    }

    #[Test]
    public function the_search_page_falls_back_to_the_database_when_meilisearch_cannot_be_reached(): void
    {
        $this->artisan('metaforge:search:sync')->assertSuccessful();
        Blog::factory()->published()->create(['title' => 'Kubernetes basics', 'user_id' => $this->author()->id]);
        $this->settle();

        $this->useMeilisearch('http://127.0.0.1:9', 'unreachable');
        $this->assertFalse((new MeilisearchCircuitBreaker)->isOpen());

        $this->get(route('search.results', ['q' => 'kubernetes']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('results.blogs.meta.total', 1)
            ->where('results.blogs.items.0.title', 'Kubernetes basics'));

        $this->assertTrue((new MeilisearchCircuitBreaker)->isOpen(), 'and it does not try again for a while');

        $report = $this->health();
        $this->assertSame(SearchHealthReport::DOWN, $report->status);
        $this->assertTrue($report->circuitOpen);
        $this->assertStringContainsString('cannot be reached at http://127.0.0.1:9', $report->problems[0]);
    }

    #[Test]
    public function saving_a_post_while_meilisearch_is_down_fails_in_the_request_and_succeeds_when_indexing_is_queued(): void
    {
        $this->useMeilisearch('http://127.0.0.1:9', 'unreachable');

        // Indexing while the post is saved: the post is stored and the request then fails.
        config(['scout.queue' => false]);

        try {
            Blog::factory()->published()->create(['title' => 'Saved while search was down', 'user_id' => $this->author()->id]);
            $this->fail('Saving a post with Meilisearch down was expected to fail when indexing is not queued.');
        } catch (CommunicationException) {
            $this->assertSame(1, Blog::query()->count(), 'the post was stored before the failure');
        }

        // Indexing on the queue: the save works and the job is what waits for Meilisearch.
        config(['scout.queue' => true]);
        Queue::fake();

        Blog::factory()->published()->create(['title' => 'Saved on the queue', 'user_id' => $this->author()->id]);

        $this->assertSame(2, Blog::query()->count());
        Queue::assertPushed(MakeSearchable::class);
    }

    // --- The health check, against what is really there ---------------------------------------------------------------------------

    #[Test]
    public function a_document_that_never_reached_the_index_is_noticed_and_a_reindex_puts_it_right(): void
    {
        $author = $this->author();
        Blog::factory()->published()->create(['title' => 'First post', 'user_id' => $author->id]);
        $this->artisan('metaforge:search:reindex')->assertSuccessful();
        $this->settle();
        $this->assertSame(SearchHealthReport::HEALTHY, $this->health()->status);

        // Saved while nothing was listening, as when Meilisearch was down or the post was imported by a script.
        Blog::withoutSyncingToSearch(fn () => Blog::factory()->published()->create(['title' => 'Second post', 'user_id' => $author->id]));

        $report = $this->health();
        $this->assertSame(SearchHealthReport::ATTENTION, $report->status);
        $this->assertSame(['The ['.(new Blog)->indexableAs().'] index has 1 document and 2 should be there. Run: php artisan metaforge:search:reindex'], $report->problems);
        $this->artisan('metaforge:search:health')->assertFailed();

        $this->artisan('metaforge:search:reindex')->assertSuccessful();
        $this->settle();
        $this->assertSame(SearchHealthReport::HEALTHY, $this->health()->status);
        $this->artisan('metaforge:search:health')->assertSuccessful();
    }

    #[Test]
    public function settings_changed_on_the_server_are_noticed_and_a_sync_puts_them_right(): void
    {
        $this->artisan('metaforge:search:sync')->assertSuccessful();
        $this->settle();
        $this->assertSame(SearchHealthReport::HEALTHY, $this->health()->status);

        $index = $this->meili->index((new Blog)->indexableAs());
        $this->meili->waitForTask($index->updateRankingRules(['words', 'typo'])['taskUid']);

        $report = $this->health();
        $this->assertSame(SearchHealthReport::ATTENTION, $report->status);
        $this->assertSame(['The ['.(new Blog)->indexableAs().'] settings are not the ones this app expects. Run: php artisan metaforge:search:sync'], $report->problems);

        $this->artisan('metaforge:search:sync')->assertSuccessful();
        $this->settle();
        $this->assertSame(SearchHealthReport::HEALTHY, $this->health()->status);
    }

    #[Test]
    public function an_index_that_was_never_made_is_one_line_telling_the_site_to_reindex(): void
    {
        $report = $this->health();

        $this->assertSame(SearchHealthReport::ATTENTION, $report->status);
        $this->assertSame(['No search index exists yet. Run: php artisan metaforge:search:reindex'], $report->problems);
    }

    #[Test]
    public function a_key_the_server_does_not_accept_is_called_down_and_never_repeated(): void
    {
        $this->useMeilisearch((string) getenv('MEILISEARCH_LIVE_HOST'), $this->wrongKey());

        $report = $this->health();

        $this->assertSame(SearchHealthReport::DOWN, $report->status);
        $this->assertStringContainsString('refused the key (HTTP 403)', $report->problems[0]);
        $this->assertStringNotContainsString($this->wrongKey(), (string) json_encode($report->toArray()));

        Artisan::call('metaforge:search:health');
        $this->assertStringNotContainsString($this->wrongKey(), Artisan::output());
    }

    #[Test]
    public function no_key_at_all_is_called_down_and_says_to_set_one(): void
    {
        $this->useMeilisearch((string) getenv('MEILISEARCH_LIVE_HOST'), null);

        $report = $this->health();

        $this->assertSame(SearchHealthReport::DOWN, $report->status);
        $this->assertSame(['Meilisearch needs a key and was not given one (HTTP 401). Set MEILISEARCH_KEY.'], $report->problems);
    }

    // --- The key recipe in the docs ----------------------------------------------------------------------------------------------

    /**
     * What the docs tell a site to allow a key to do (docs/search-index.md, "Keys"). If the app comes to need another action this
     * fails, and the docs and this list change together.
     *
     * @return list<string>
     */
    public static function actionsTheAppNeeds(): array
    {
        return [
            'search',
            'documents.add',
            'documents.delete',
            'indexes.create',
            'indexes.get',
            'indexes.delete',
            'settings.get',
            'settings.update',
            'tasks.get',
            'stats.get',
            'version',
        ];
    }

    #[Test]
    public function a_key_with_only_the_actions_the_docs_list_does_everything_the_app_does(): void
    {
        $key = $this->meili->createKey([
            'name' => 'metaforge test '.$this->prefix,
            'description' => 'Made by the live search tests; removed afterwards.',
            'actions' => self::actionsTheAppNeeds(),
            'indexes' => [$this->prefix.'*'],
            'expiresAt' => null,
        ]);

        try {
            $this->useMeilisearch((string) getenv('MEILISEARCH_LIVE_HOST'), $key->getKey());
            Blog::factory()->published()->create(['title' => 'Kubernetes basics', 'user_id' => $this->author()->id]);

            $this->artisan('metaforge:search:reindex')->assertSuccessful();
            $this->settle();
            $this->assertSame(['Kubernetes basics'], $this->titles('kubernets'));
            $this->get(route('search.results', ['q' => 'kubernets']))->assertOk()->assertInertia(fn (Assert $page) => $page->where('results.blogs.meta.total', 1));
            $report = $this->health();
            $this->assertSame(SearchHealthReport::HEALTHY, $report->status, implode(' ', $report->problems));
            $this->assertNotNull($report->version, 'the version action is among them');
        } finally {
            $this->useMeilisearch((string) getenv('MEILISEARCH_LIVE_HOST'), getenv('MEILISEARCH_LIVE_KEY') ?: null);
            $this->meili->deleteKey($key->getKey());
        }
    }

    #[Test]
    public function a_key_that_lacks_one_of_those_actions_is_refused_where_it_matters(): void
    {
        $key = $this->meili->createKey([
            'name' => 'metaforge test without settings '.$this->prefix,
            'actions' => array_values(array_diff(self::actionsTheAppNeeds(), ['settings.update'])),
            'indexes' => [$this->prefix.'*'],
            'expiresAt' => null,
        ]);

        try {
            $this->useMeilisearch((string) getenv('MEILISEARCH_LIVE_HOST'), $key->getKey());

            $this->artisan('metaforge:search:sync')->assertFailed();
        } finally {
            $this->useMeilisearch((string) getenv('MEILISEARCH_LIVE_HOST'), getenv('MEILISEARCH_LIVE_KEY') ?: null);
            $this->meili->deleteKey($key->getKey());
        }
    }
}
