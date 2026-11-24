<?php

namespace Tests\Feature\Housekeeping;

use App\Jobs\PruneFailedJobs;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Failed\NullFailedJobProvider;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The record of a job that failed is kept for `queue.failed.retention_days`, and for ever if that is 0.
 *
 * Laravel writes a row to `failed_jobs` (the whole job and the exception with its stack trace) for every job that gives up, and ships
 * `queue:prune-failed` to delete them, which nothing ran: the table only grew.
 */
class FailedJobsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-21 12:00:00'));
    }

    /**
     * One row for each age in the list (how long ago it failed, in words Carbon reads: "3 days"), written the way a worker writes it.
     *
     * @param  list<string>  $ago
     * @return list<string> the uuids, in the order of the list
     */
    private function failures(array $ago): array
    {
        $uuids = [];

        foreach ($ago as $age) {
            $this->travelTo(CarbonImmutable::parse('2026-11-21 12:00:00')->sub($age));

            $uuids[] = (string) app('queue.failer')->log('database', 'default', json_encode(['uuid' => 'job-'.count($uuids), 'displayName' => 'App\\Jobs\\Example']), new RuntimeException('It failed'));
        }

        $this->travelTo(CarbonImmutable::parse('2026-11-21 12:00:00'));

        return $uuids;
    }

    /**
     * @return list<string>
     */
    private function uuidsLeft(): array
    {
        return DB::table('failed_jobs')->orderBy('id')->pluck('uuid')->all();
    }

    #[Test]
    public function the_record_keeps_thirty_days_unless_told_otherwise(): void
    {
        $this->assertSame(30, config('queue.failed.retention_days'));
        $this->assertSame('database-uuids', config('queue.failed.driver'));
    }

    #[Test]
    public function nothing_is_deleted_unless_a_retention_is_set(): void
    {
        $this->failures(['10 years']);

        config(['queue.failed.retention_days' => 0]);
        (new PruneFailedJobs)->handle();

        config(['queue.failed.retention_days' => -30]);
        (new PruneFailedJobs)->handle();

        $this->assertSame(1, DB::table('failed_jobs')->count());
    }

    #[Test]
    public function rows_older_than_the_retention_are_deleted_and_newer_ones_are_kept(): void
    {
        config(['queue.failed.retention_days' => 30]);
        [, , $exactly, $inside, $today] = $this->failures(['31 days', '30 days 1 second', '30 days', '29 days', '1 hour']);

        (new PruneFailedJobs)->handle();

        $this->assertSame([$exactly, $inside, $today], $this->uuidsLeft(), 'a row exactly as old as the retention is not older than it');
    }

    #[Test]
    public function a_config_file_without_the_setting_still_keeps_thirty_days(): void
    {
        // A `config/queue.php` a site edited before this setting existed has no `retention_days` under `failed`.
        config(['queue.failed' => Arr::except(config('queue.failed'), 'retention_days')]);
        $this->assertArrayNotHasKey('retention_days', config('queue.failed'));
        [, $kept] = $this->failures(['31 days', '29 days']);

        (new PruneFailedJobs)->handle();

        $this->assertSame([$kept], $this->uuidsLeft());
    }

    #[Test]
    public function the_retention_is_counted_in_days(): void
    {
        config(['queue.failed.retention_days' => 2]);
        [, $inside] = $this->failures(['49 hours', '47 hours']);

        (new PruneFailedJobs)->handle();

        $this->assertSame([$inside], $this->uuidsLeft());
    }

    #[Test]
    public function a_large_backlog_is_all_deleted_not_just_the_first_chunk(): void
    {
        config(['queue.failed.retention_days' => 30]);
        $old = now()->subDays(100);

        $rows = array_map(fn (int $i) => [
            'uuid' => 'old-'.$i, 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => $old,
        ], range(1, 2300));

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('failed_jobs')->insert($chunk);
        }
        [$kept] = $this->failures(['1 hour']);

        (new PruneFailedJobs)->handle();

        $this->assertSame([$kept], $this->uuidsLeft());
    }

    #[Test]
    public function a_storage_that_cannot_prune_is_left_alone(): void
    {
        config(['queue.failed.retention_days' => 1]);
        $this->failures(['10 days']);

        // `QUEUE_FAILED_DRIVER=null` records nothing and has nothing to delete; the job must not fail on it, or it would fail every night.
        $this->app->instance('queue.failer', new NullFailedJobProvider);

        (new PruneFailedJobs)->handle();

        $this->assertSame(1, DB::table('failed_jobs')->count(), 'the job deleted from a table the configured storage does not own');
    }

    #[Test]
    public function the_listing_of_failed_jobs_loses_the_rows_that_were_deleted(): void
    {
        config(['queue.failed.retention_days' => 7]);
        $this->failures(['8 days', '1 day']);

        (new PruneFailedJobs)->handle();

        $this->artisan('queue:failed')->expectsOutputToContain('2026-11-20')->doesntExpectOutputToContain('2026-11-13')->assertSuccessful();
    }

    #[Test]
    public function the_job_is_scheduled_every_night_after_the_reset_tokens(): void
    {
        $events = $this->events('PruneFailedJobs');

        $this->assertCount(1, $events);
        $this->assertSame('30 3 * * *', $events->first()->expression);
    }

    /**
     * @return Collection<int, Event>
     */
    private function events(string $needle)
    {
        // The schedule is put together when the console kernel is booted, which a command does.
        $this->artisan('schedule:list')->assertSuccessful();

        return collect(app(Schedule::class)->events())
            ->filter(fn (Event $event) => str_contains((string) $event->description, $needle) || str_contains((string) $event->command, $needle) || str_contains($event->getSummaryForDisplay(), $needle))
            ->values();
    }
}
