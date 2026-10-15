<?php

namespace Tests\Feature\Tebex;

use App\Jobs\SyncTebexCatalogue;
use App\Models\TebexPackage;
use App\Models\TebexSync;
use App\Models\User;
use App\Payments\Tebex\TebexCatalogue;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithTebex;
use Tests\TestCase;

/**
 * Running the sync by hand, from the queue and from the scheduler.
 */
class TebexSyncCommandTest extends TestCase
{
    use InteractsWithTebex;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTebex();
    }

    #[Test]
    public function the_command_refreshes_the_catalogue_and_says_what_changed(): void
    {
        $this->fakeTebex();

        $this->artisan('tebex:sync')
            ->expectsTable(['', 'Added', 'Changed', 'Unchanged', 'No longer listed'], [
                ['Categories', 2, 0, 0, 0],
                ['Packages', 3, 0, 0, 0],
            ])
            ->assertSuccessful();

        $this->assertSame(3, TebexPackage::query()->count());
        $this->assertSame(TebexSync::COMMAND, TebexSync::query()->sole()->trigger);
    }

    #[Test]
    public function the_command_fails_with_the_reason_when_tebex_does(): void
    {
        $this->fakeTebexHttp(['headless.tebex.io/*' => Http::response(['title' => 'Nope'], 403)]);

        $this->artisan('tebex:sync')
            ->expectsOutputToContain('Tebex answered 403')
            ->assertFailed();
    }

    #[Test]
    public function the_command_says_so_when_tebex_is_not_set_up(): void
    {
        Http::fake();
        config(['tebex.public_token' => null]);

        $this->artisan('tebex:sync')
            ->expectsOutputToContain('TEBEX_PUBLIC_TOKEN')
            ->assertFailed();

        Http::assertNothingSent();
    }

    #[Test]
    public function the_job_runs_a_sync_for_the_person_who_asked(): void
    {
        $this->fakeTebex();
        $staff = User::factory()->create();

        SyncTebexCatalogue::dispatchSync(TebexSync::ADMIN, $staff->id);

        $run = TebexSync::query()->sole();
        $this->assertSame(TebexSync::OK, $run->status);
        $this->assertSame($staff->id, $run->user_id);
    }

    #[Test]
    public function the_job_does_nothing_when_tebex_is_not_set_up_and_does_not_fail_when_a_run_is_in_progress(): void
    {
        Http::fake();
        config(['tebex.public_token' => null]);

        SyncTebexCatalogue::dispatchSync();

        $this->assertSame(0, TebexSync::query()->count());

        config(['tebex.public_token' => $this->tebexToken]);
        $this->fakeTebex();
        $lock = Cache::lock('tebex:sync', 60);
        $lock->get();

        SyncTebexCatalogue::dispatchSync();

        $lock->release();
        $this->assertSame(0, TebexSync::query()->count());
    }

    #[Test]
    public function the_job_records_a_failure_instead_of_throwing_so_a_store_that_is_down_does_not_fill_the_failed_jobs_table(): void
    {
        $this->fakeTebexHttp(['headless.tebex.io/*' => Http::response([], 500)]);

        SyncTebexCatalogue::dispatchSync();

        $this->assertSame(TebexSync::FAILED, TebexSync::query()->sole()->status);
    }

    #[Test]
    public function the_scheduler_has_it_and_only_runs_it_when_it_is_due(): void
    {
        config(['tebex.sync.every_minutes' => 60]);

        // The schedule exists once the console has been started.
        $this->artisan('schedule:list')->expectsOutputToContain('SyncTebexCatalogue')->assertSuccessful();

        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains((string) $event->description, 'SyncTebexCatalogue'));

        $this->assertNotNull($event, 'the job is on the schedule');
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->filtersPass($this->app), 'never run, so due');

        $this->fakeTebex();
        app(TebexCatalogue::class)->sync(TebexSync::SCHEDULE);

        $this->assertFalse($event->filtersPass($this->app), 'just run, so not due');
    }
}
