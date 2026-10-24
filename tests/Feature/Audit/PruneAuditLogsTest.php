<?php

namespace Tests\Feature\Audit;

use App\Jobs\PruneAuditLogs;
use App\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The log is kept for as long as `audit.retention_days` says, and for ever if it says 0.
 */
class PruneAuditLogsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00'));
    }

    #[Test]
    public function nothing_is_ever_deleted_unless_a_retention_is_set(): void
    {
        AuditLog::factory()->create(['created_at' => now()->subYears(10)]);

        config(['audit.retention_days' => 0]);
        (new PruneAuditLogs)->handle();

        config(['audit.retention_days' => -30]);
        (new PruneAuditLogs)->handle();

        $this->assertSame(1, AuditLog::query()->count());
    }

    #[Test]
    public function entries_older_than_the_retention_are_deleted_and_newer_ones_are_kept(): void
    {
        config(['audit.retention_days' => 90]);
        $old = AuditLog::factory()->create(['created_at' => now()->subDays(91)]);
        $edge = AuditLog::factory()->create(['created_at' => now()->subDays(90)->addSecond()]);
        $exactly = AuditLog::factory()->create(['created_at' => now()->subDays(90)]);
        $recent = AuditLog::factory()->create(['created_at' => now()->subDay()]);

        (new PruneAuditLogs)->handle();

        $this->assertModelMissing($old);
        $this->assertModelExists($edge);
        $this->assertModelExists($exactly, 'an entry exactly as old as the retention is not older than it');
        $this->assertModelExists($recent);
    }

    #[Test]
    public function a_large_backlog_is_all_deleted_not_just_the_first_chunk(): void
    {
        config(['audit.retention_days' => 30]);
        $rows = array_map(fn (int $i) => ['actor_label' => 'Old', 'event' => 'users.ban', 'outcome' => 'succeeded', 'created_at' => now()->subDays(100)], range(1, 5200));

        foreach (array_chunk($rows, 500) as $chunk) {
            AuditLog::query()->insert($chunk);
        }
        AuditLog::factory()->create(['created_at' => now()]);

        (new PruneAuditLogs)->handle();

        $this->assertSame(1, AuditLog::query()->count());
    }

    #[Test]
    public function the_application_still_cannot_delete_an_entry_itself(): void
    {
        config(['audit.retention_days' => 1]);
        $old = AuditLog::factory()->create(['created_at' => now()->subDays(5)]);

        try {
            $old->delete();
            $this->fail('the model deleted an entry');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('PruneAuditLogs', $exception->getMessage());
        }

        (new PruneAuditLogs)->handle();

        $this->assertModelMissing($old);
    }

    #[Test]
    public function the_job_is_scheduled_every_day(): void
    {
        $this->artisan('schedule:list')->assertSuccessful();

        $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains((string) $event->description, 'PruneAuditLogs') || str_contains($event->command ?? '', 'PruneAuditLogs'));

        $this->assertCount(1, $events);
        $this->assertSame('0 2 * * *', $events->first()->expression);
    }
}
