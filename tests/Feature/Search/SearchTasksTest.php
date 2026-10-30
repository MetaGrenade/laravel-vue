<?php

namespace Tests\Feature\Search;

use App\Support\Search\RefusedUpdates;
use App\Support\Search\SearchTasks;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeMeilisearch;
use Tests\TestCase;

/**
 * Meilisearch's tasks (docs/search-index.md): where a rebuild begins, waiting for what it sent, and what was refused from there on. The
 * stand-in answers as the real engine does (tested against Meilisearch 1.12): `total` is every task that matches, whatever `from` says.
 */
class SearchTasksTest extends TestCase
{
    private const INDEXES = ['blogs', 'forum_threads', 'faqs', 'products'];

    /**
     * @param  list<array<string, mixed>>  $log
     */
    private function tasks(array &$log, ?\Closure $onAsk = null, ?FakeMeilisearch &$fake = null): SearchTasks
    {
        $fake = FakeMeilisearch::serving()->taskLog($log, $onAsk);

        return new SearchTasks($fake->client());
    }

    // --- Where a rebuild begins ---------------------------------------------------------------------------------------------------

    #[Test]
    public function a_rebuild_begins_at_the_task_after_the_newest_one(): void
    {
        $log = [FakeMeilisearch::task(4), FakeMeilisearch::task(5, 'failed', 'blogs', 'Nope.'), FakeMeilisearch::task(6, 'enqueued', 'other')];

        $this->assertSame(7, $this->tasks($log, fake: $fake)->nextUid(), 'the newest of any index, and one more');
        $this->assertSame(['GET /tasks?limit=1'], $fake->requests);
    }

    #[Test]
    public function with_no_task_at_all_a_rebuild_begins_at_the_first_task_there_will_be(): void
    {
        $log = [];

        $this->assertSame(0, $this->tasks($log)->nextUid());
    }

    // --- Waiting -------------------------------------------------------------------------------------------------------------------

    #[Test]
    public function nothing_to_wait_for_is_done_at_once(): void
    {
        Sleep::fake();
        $log = [FakeMeilisearch::task(1), FakeMeilisearch::task(2, 'failed')];

        $this->assertTrue($this->tasks($log, fake: $fake)->settle(self::INDEXES, 30));

        $this->assertCount(1, $fake->requests);
        $this->assertStringContainsString('statuses=enqueued,processing', $fake->requests[0]);
        $this->assertStringContainsString('indexUids=blogs,forum_threads,faqs,products', $fake->requests[0]);
        Sleep::assertNeverSlept();
    }

    #[Test]
    public function it_waits_for_tasks_that_are_enqueued_or_processing_and_stops_when_they_are_done(): void
    {
        Sleep::fake();
        $log = [FakeMeilisearch::task(1, 'enqueued'), FakeMeilisearch::task(2, 'processing')];

        // The first asking finds both; by the second one has finished; by the third the other has too.
        $tasks = $this->tasks($log, function (int $asked) use (&$log) {
            if ($asked === 2) {
                $log[0]['status'] = 'succeeded';
            }

            if ($asked === 3) {
                $log[1]['status'] = 'failed';
            }
        }, $fake);

        $this->assertTrue($tasks->settle(self::INDEXES, 30));

        $this->assertCount(3, $fake->requests, 'asked until none was left');
        Sleep::assertSleptTimes(2);
    }

    #[Test]
    public function tasks_of_other_indexes_are_not_waited_for(): void
    {
        Sleep::fake();
        $log = [FakeMeilisearch::task(1, 'processing', 'somebody_elses_index')];

        $this->assertTrue($this->tasks($log)->settle(self::INDEXES, 30));
    }

    #[Test]
    public function it_gives_up_after_the_seconds_it_was_given(): void
    {
        Sleep::fake();
        $log = [FakeMeilisearch::task(1, 'processing')];

        $this->assertFalse($this->tasks($log, fake: $fake)->settle(self::INDEXES, 2));

        $this->assertCount(8, $fake->requests, 'four a second');
        Sleep::assertSleptTimes(7);
        Sleep::assertSlept(fn ($duration) => $duration->totalMilliseconds === 250.0, 7);
    }

    #[Test]
    public function with_no_time_it_asks_once(): void
    {
        Sleep::fake();
        $log = [FakeMeilisearch::task(1, 'processing')];

        $this->assertFalse($this->tasks($log, fake: $fake)->settle(self::INDEXES, 0));

        $this->assertCount(1, $fake->requests);
        Sleep::assertNeverSlept();
    }

    // --- What was refused ----------------------------------------------------------------------------------------------------------

    #[Test]
    public function refusals_are_counted_from_a_task_number_and_not_from_the_total_meilisearch_gives(): void
    {
        $log = [
            FakeMeilisearch::task(3, 'failed', 'blogs', 'Before the rebuild.'),
            FakeMeilisearch::task(5, 'failed', 'blogs', 'First of the rebuild.'),
            FakeMeilisearch::task(6, 'succeeded'),
            FakeMeilisearch::task(7, 'failed', 'faqs', 'Second of the rebuild.'),
        ];
        $tasks = $this->tasks($log, fake: $fake);

        $refused = $tasks->refusedFrom(self::INDEXES, 5);

        $this->assertSame(2, $refused->count, 'the three that failed in all would be the total');
        $this->assertFalse($refused->more);
        $this->assertSame('First of the rebuild.', $refused->firstReason, 'the first thing that went wrong, which is the oldest');
        $this->assertStringContainsString('reverse=true', $fake->requests[0]);
        $this->assertStringContainsString('from=5', $fake->requests[0]);
        $this->assertStringContainsString('statuses=failed', $fake->requests[0]);
        $this->assertStringContainsString('limit=100', $fake->requests[0]);
    }

    #[Test]
    public function the_task_a_rebuild_begins_at_is_counted_and_the_one_before_it_is_not(): void
    {
        $log = [FakeMeilisearch::task(4, 'failed', 'blogs', 'Before.'), FakeMeilisearch::task(5, 'failed', 'blogs', 'At the start.')];
        $tasks = $this->tasks($log);

        $this->assertSame(1, $tasks->refusedFrom(self::INDEXES, 5)->count);
        $this->assertSame(0, $tasks->refusedFrom(self::INDEXES, 6)->count);
        $this->assertSame(2, $tasks->refusedFrom(self::INDEXES, 4)->count);
    }

    #[Test]
    public function the_very_first_task_there_is_can_be_a_refusal_that_is_counted(): void
    {
        $log = [FakeMeilisearch::task(0, 'failed', 'blogs', 'The first of all.')];

        $this->assertSame(1, $this->tasks($log)->refusedFrom(self::INDEXES, 0)->count);
    }

    #[Test]
    public function only_the_apps_indexes_are_asked_about(): void
    {
        $log = [FakeMeilisearch::task(5, 'failed', 'somebody_elses_index', 'Not ours.'), FakeMeilisearch::task(6, 'failed', 'products', 'Ours.')];
        $tasks = $this->tasks($log, fake: $fake);

        $refused = $tasks->refusedFrom(self::INDEXES, 0);

        $this->assertSame(1, $refused->count);
        $this->assertSame('Ours.', $refused->firstReason);
        $this->assertStringContainsString('indexUids=blogs,forum_threads,faqs,products', $fake->requests[0]);
    }

    #[Test]
    public function with_no_point_to_count_from_everything_that_was_ever_refused_is_counted_newest_first(): void
    {
        $log = [FakeMeilisearch::task(1, 'failed', 'blogs', 'Old.'), FakeMeilisearch::task(2, 'succeeded'), FakeMeilisearch::task(9, 'failed', 'blogs', 'New.')];
        $tasks = $this->tasks($log, fake: $fake);

        $refused = $tasks->refusedFrom(self::INDEXES, null);

        $this->assertSame(2, $refused->count);
        $this->assertSame('New.', $refused->firstReason);
        $this->assertStringNotContainsString('reverse', $fake->requests[0]);
        $this->assertStringNotContainsString('from=', $fake->requests[0]);
    }

    #[Test]
    public function more_refusals_than_a_page_are_said_to_be_more(): void
    {
        $log = [];

        for ($uid = 1; $uid <= 150; $uid++) {
            $log[] = FakeMeilisearch::task($uid, 'failed', 'blogs', "Refusal {$uid}.");
        }

        $refused = $this->tasks($log)->refusedFrom(self::INDEXES, 1);

        $this->assertSame(100, $refused->count);
        $this->assertTrue($refused->more);
        $this->assertSame('Refusal 1.', $refused->firstReason);
        $this->assertSame('Meilisearch refused 100 or more updates in this rebuild: Refusal 1.', $refused->sentence('in this rebuild'));
    }

    #[Test]
    public function with_no_point_to_count_from_the_total_meilisearch_gives_is_used_even_past_a_page(): void
    {
        $log = [];

        for ($uid = 1; $uid <= 150; $uid++) {
            $log[] = FakeMeilisearch::task($uid, 'failed', 'blogs', "Refusal {$uid}.");
        }

        $refused = $this->tasks($log)->refusedFrom(self::INDEXES, null);

        $this->assertSame(150, $refused->count, 'all of them, and not only the page that came back');
        $this->assertFalse($refused->more);
        $this->assertSame('Refusal 150.', $refused->firstReason, 'the newest');
    }

    #[Test]
    public function the_tasks_the_app_hands_out_ask_with_the_configured_host_key_and_timeouts(): void
    {
        // A made-up word repeated, which the secrets scan does not take for a key (docs/secrets-scan.md).
        $key = str_repeat('canary-', 3).'key';
        config(['search.health.timeout_seconds' => 4, 'scout.meilisearch.host' => 'http://search.example.test:7700', 'scout.meilisearch.key' => $key]);

        $client = (new \ReflectionProperty(SearchTasks::class, 'client'))->getValue(app(SearchTasks::class));
        $adapter = (new \ReflectionProperty($client, 'http'))->getValue($client);
        $guzzle = (new \ReflectionProperty($adapter, 'http'))->getValue($adapter);

        $this->assertSame('http://search.example.test:7700', (new \ReflectionProperty($adapter, 'baseUrl'))->getValue($adapter));
        $this->assertSame("Bearer {$key}", (new \ReflectionProperty($adapter, 'headers'))->getValue($adapter)['Authorization']);
        $this->assertSame(4, $guzzle->getConfig('timeout'), 'so that a silent Meilisearch does not hold a rebuild up on every question');
    }

    #[Test]
    public function exactly_a_page_is_not_more(): void
    {
        $log = [];

        for ($uid = 1; $uid <= 100; $uid++) {
            $log[] = FakeMeilisearch::task($uid, 'failed', 'blogs', 'Refused.');
        }

        $refused = $this->tasks($log)->refusedFrom(self::INDEXES, 1);

        $this->assertSame(100, $refused->count);
        $this->assertFalse($refused->more);
    }

    #[Test]
    public function nothing_refused_is_nothing(): void
    {
        $log = [FakeMeilisearch::task(1), FakeMeilisearch::task(2, 'enqueued')];

        $refused = $this->tasks($log)->refusedFrom(self::INDEXES, 0);

        $this->assertFalse($refused->any());
        $this->assertSame(0, $refused->count);
        $this->assertNull($refused->firstReason);
    }

    #[Test]
    public function a_reason_is_cut_short_when_it_is_very_long(): void
    {
        $log = [FakeMeilisearch::task(1, 'failed', 'blogs', str_repeat('long ', 100))];

        $this->assertLessThanOrEqual(203, strlen((string) $this->tasks($log)->refusedFrom(self::INDEXES, 0)->firstReason));
    }

    // --- Saying it -------------------------------------------------------------------------------------------------------------------

    #[Test]
    public function a_refusal_is_said_in_words_with_a_full_stop(): void
    {
        $this->assertSame('Meilisearch refused 1 update so far.', (new RefusedUpdates(1))->sentence('so far'));
        $this->assertSame('Meilisearch refused 3 updates since the last rebuild began.', (new RefusedUpdates(3))->sentence('since the last rebuild began'));
        $this->assertSame('Meilisearch refused 1 update in this rebuild: Document is wrong.', (new RefusedUpdates(1, false, 'Document is wrong.'))->sentence('in this rebuild'), 'one full stop, not two');
        $this->assertSame('Meilisearch refused 2 updates so far: No full stop here.', (new RefusedUpdates(2, false, 'No full stop here'))->sentence('so far'));
        $this->assertFalse((new RefusedUpdates)->any());
        $this->assertTrue((new RefusedUpdates(1))->any());
    }
}
