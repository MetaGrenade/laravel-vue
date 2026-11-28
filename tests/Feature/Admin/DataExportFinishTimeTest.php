<?php

namespace Tests\Feature\Admin;

use App\Models\DataExport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The member's 30 minutes run from the time an export was completed, and staff can type that time (Admin → Trust & safety). It could
 * be any date: one next year kept the zip, which holds everything the site keeps about the member, and the member's link, until then;
 * and saving a finished export again with the time of the moment began the window over. A finish is now never later than the time the
 * file was written, nor later than now.
 */
class DataExportFinishTimeTest extends TestCase
{
    use RefreshDatabase;

    private const IN_THE_FUTURE = 'The time cannot be in the future.';

    private const AFTER_THE_FILE = 'The time cannot be after the file was written. Leave it empty to use that time.';

    private const TOO_LATE = 'Data export updated, but its 30 minutes are already over, so the member cannot download the file.';

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2026-11-27 12:00:00'));

        Role::create(['name' => 'moderator', 'guard_name' => 'web']);
        Permission::create(['name' => 'trust_safety.acp.view', 'guard_name' => 'web']);
        Permission::create(['name' => 'trust_safety.acp.edit', 'guard_name' => 'web']);

        $this->agent = User::factory()->create();
        $this->agent->assignRole('moderator');
        $this->agent->givePermissionTo(['trust_safety.acp.view', 'trust_safety.acp.edit']);
    }

    private function pending(): DataExport
    {
        return DataExport::factory()->create(['status' => DataExport::STATUS_PENDING]);
    }

    private function path(DataExport $export): string
    {
        return DataExport::filePrefix($export->user_id).'1.zip';
    }

    /**
     * The export's file on the disk, written that many seconds ago.
     */
    private function written(DataExport $export, int $secondsAgo): string
    {
        $path = $this->path($export);
        Storage::disk('local')->put($path, 'zip');
        touch(Storage::disk('local')->path($path), now()->subSeconds($secondsAgo)->getTimestamp());

        return $path;
    }

    private function at(int $secondsFromNow): string
    {
        return now()->addSeconds($secondsFromNow)->toIso8601String();
    }

    private function save(DataExport $export, mixed $completedAt, ?string $path = null, string $status = DataExport::STATUS_COMPLETED): TestResponse
    {
        return $this->actingAs($this->agent)->patch(route('acp.trust-safety.exports.update', $export), [
            'status' => $status,
            'file_path' => $path ?? $this->path($export),
            'completed_at' => $completedAt,
        ]);
    }

    private function assertCompleted(DataExport $export, int $secondsFromNow, string $why = ''): void
    {
        $stored = $export->fresh()->completed_at;

        $this->assertNotNull($stored, $why);
        $this->assertSame(now()->addSeconds($secondsFromNow)->toDateTimeString(), $stored->toDateTimeString(), $why);
    }

    private function assertUnchanged(DataExport $export): void
    {
        $export->refresh();

        $this->assertSame(DataExport::STATUS_PENDING, $export->status);
        $this->assertNull($export->completed_at);
        $this->assertNull($export->file_path);
    }

    #[Test]
    public function a_time_that_is_still_to_come_is_refused(): void
    {
        $export = $this->pending();
        $this->written($export, 0);

        foreach ([5 * 60 + 1, 86400, 365 * 86400] as $ahead) {
            $this->save($export, $this->at($ahead))->assertSessionHasErrors(['completed_at' => self::IN_THE_FUTURE]);
        }

        $this->assertUnchanged($export);
    }

    #[Test]
    public function a_time_still_to_come_is_refused_when_there_is_no_file_yet_too(): void
    {
        // A file put there afterwards would have had a window that began on the date typed.
        $export = $this->pending();

        $this->save($export, $this->at(86400))->assertSessionHasErrors(['completed_at' => self::IN_THE_FUTURE]);

        $this->assertUnchanged($export);
    }

    #[Test]
    public function a_time_a_fast_clock_put_a_few_minutes_ahead_is_taken_for_now(): void
    {
        $export = $this->pending();
        $this->written($export, 0);

        $this->save($export, $this->at(5 * 60))->assertSessionHasNoErrors();

        $this->assertCompleted($export, 0);

        $noFile = $this->pending();
        $this->save($noFile, $this->at(5 * 60))->assertSessionHasNoErrors();

        $this->assertCompleted($noFile, 0);
    }

    #[Test]
    public function a_time_after_the_file_was_written_is_refused(): void
    {
        $export = $this->pending();
        $this->written($export, 20 * 60);

        foreach ([-15 * 60 + 1, -60, 0] as $typed) {
            $this->save($export, $this->at($typed))->assertSessionHasErrors(['completed_at' => self::AFTER_THE_FILE]);
        }

        $this->assertUnchanged($export);
    }

    #[Test]
    public function a_time_a_few_minutes_after_the_file_was_written_is_taken_back_to_it(): void
    {
        // The form cuts a time to the minute, and the clock that typed it may be fast.
        foreach ([-19 * 60, -15 * 60] as $typed) {
            $export = $this->pending();
            $this->written($export, 20 * 60);

            $this->save($export, $this->at($typed))->assertSessionHasNoErrors();

            $this->assertCompleted($export, -20 * 60, "typed $typed seconds from now");
        }
    }

    #[Test]
    public function a_file_that_says_it_was_written_later_than_now_counts_as_written_now(): void
    {
        // The clock of the disk's own server running ahead.
        $export = $this->pending();
        $this->written($export, -3600);

        $this->save($export, $this->at(3600))->assertSessionHasErrors(['completed_at' => self::IN_THE_FUTURE]);
        $this->save($export, null)->assertSessionHasNoErrors();

        $this->assertCompleted($export, 0);
    }

    #[Test]
    public function nothing_typed_is_when_the_file_was_written(): void
    {
        $export = $this->pending();
        $this->written($export, 10 * 60);

        $this->save($export, null)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Data export updated.')
            ->assertSessionMissing('warning');

        $this->assertCompleted($export, -10 * 60);
        $this->assertTrue($export->fresh()->isReady(), 'the member has the rest of the window');
        $this->assertTrue(now()->addMinutes(20)->equalTo($export->fresh()->downloadExpiresAt()));
    }

    #[Test]
    public function a_time_before_the_file_was_written_is_kept_as_typed(): void
    {
        $export = $this->pending();
        $this->written($export, 0);

        $this->save($export, $this->at(-10 * 60))->assertSessionHasNoErrors();

        $this->assertCompleted($export, -10 * 60);
    }

    #[Test]
    public function saving_a_finished_export_again_does_not_begin_its_window_again(): void
    {
        $export = $this->pending();
        $this->written($export, 0);
        $this->save($export, null)->assertSessionHasNoErrors();

        $this->travel(20)->minutes();

        // Left empty, and sent back as the form shows it.
        $this->save($export, null)->assertSessionHasNoErrors();
        $this->assertCompleted($export, -20 * 60, 'empty');
        $this->save($export, $this->at(-20 * 60))->assertSessionHasNoErrors();
        $this->assertCompleted($export, -20 * 60, 'as shown');

        $this->save($export, $this->at(0))->assertSessionHasErrors(['completed_at' => self::AFTER_THE_FILE]);
        $this->assertCompleted($export, -20 * 60, 'the time of the moment');

        $this->travel(11)->minutes();
        $this->assertTrue($export->fresh()->hasExpired());
    }

    #[Test]
    public function another_export_of_the_member_cannot_begin_the_window_of_the_same_file_again(): void
    {
        $first = $this->pending();
        $path = $this->written($first, 20 * 60);
        $second = DataExport::factory()->for($first->user)->create(['status' => DataExport::STATUS_PENDING]);

        $this->save($second, $this->at(0), $path)->assertSessionHasErrors(['completed_at' => self::AFTER_THE_FILE]);
        $this->assertUnchanged($second);

        $this->save($second, null, $path)->assertSessionHasNoErrors();
        $this->assertCompleted($second, -20 * 60);
    }

    #[Test]
    public function an_export_whose_time_is_already_over_is_saved_and_staff_are_told(): void
    {
        $oldFile = $this->pending();
        $this->written($oldFile, 31 * 60);
        $oldTime = $this->pending();
        $this->written($oldTime, 0);

        foreach ([[$oldFile, null], [$oldTime, $this->at(-31 * 60)]] as [$export, $typed]) {
            $this->save($export, $typed)
                ->assertSessionHasNoErrors()
                ->assertSessionHas('warning', self::TOO_LATE)
                ->assertSessionMissing('success');

            $this->assertSame(DataExport::STATUS_COMPLETED, $export->fresh()->status);
            $this->actingAs($export->user)
                ->get(URL::temporarySignedRoute('privacy.exports.download', now()->addMinutes(5), ['export' => $export->id]))
                ->assertStatus(410);
        }
    }

    #[Test]
    public function with_no_file_on_the_disk_a_past_time_is_kept_and_nothing_typed_is_now(): void
    {
        $past = $this->pending();
        $this->save($past, $this->at(-10 * 60))->assertSessionHasNoErrors();
        $this->assertCompleted($past, -10 * 60);

        $empty = $this->pending();
        $this->save($empty, null)->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertCompleted($empty, 0);
    }

    #[Test]
    public function an_export_that_is_not_finished_keeps_no_time_whatever_the_form_held(): void
    {
        // The form sends the time it was showing when staff change the status.
        foreach ([DataExport::STATUS_FAILED, DataExport::STATUS_PENDING, DataExport::STATUS_PROCESSING] as $status) {
            $export = DataExport::factory()->completed()->create();

            $this->save($export, $this->at(86400), status: $status)->assertSessionHasNoErrors()->assertSessionHas('success');

            $this->assertSame($status, $export->fresh()->status);
            $this->assertNull($export->fresh()->completed_at, $status);
        }
    }

    #[Test]
    public function a_refused_path_is_not_looked_up_on_the_disk(): void
    {
        $export = $this->pending();

        // The disk throws for a way out of its root, so asking it would answer 500 where the form should say what is wrong.
        foreach (['exports/../../../outside.zip', ''] as $path) {
            $this->save($export, $this->at(0), $path)
                ->assertRedirect()
                ->assertSessionHasErrors('file_path')
                ->assertSessionDoesntHaveErrors('completed_at');
        }

        $this->assertUnchanged($export);
    }

    #[Test]
    public function what_is_not_a_date_is_refused_as_that(): void
    {
        $export = $this->pending();
        $this->written($export, 0);

        foreach (['soon', ['2026-11-27']] as $typed) {
            $this->save($export, $typed)->assertRedirect()->assertSessionHasErrors('completed_at');
        }

        $this->assertUnchanged($export);
    }
}
