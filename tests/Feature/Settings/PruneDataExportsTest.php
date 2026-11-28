<?php

namespace Tests\Feature\Settings;

use App\Jobs\GenerateUserDataExport;
use App\Jobs\PruneDataExports;
use App\Models\DataExport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The zip of a data export is deleted when its download window is over.
 *
 * It holds everything the site keeps about a member, and the member is told the link works for 30 minutes. Before the job, the file
 * was deleted only if somebody asked for the export again after the window: the privacy page, or the download link. A member who
 * downloaded it in time and never came back left it on the disk for good (so did a member whose account was deleted, since the row
 * went with the account and the file did not).
 */
class PruneDataExportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->travelTo(CarbonImmutable::parse('2026-11-16 12:00:00'));
    }

    /**
     * A file on the disk that is as old as the seconds say (what the operating system writes as its time of change).
     */
    private function file(string $path, int $secondsOld = 0): string
    {
        Storage::disk('local')->put($path, 'zip');
        touch(Storage::disk('local')->path($path), now()->subSeconds($secondsOld)->getTimestamp());

        return $path;
    }

    /**
     * A finished export, its window opened that many seconds ago, with its file on the disk.
     */
    private function export(int $secondsAgo, ?User $member = null, ?string $path = null): DataExport
    {
        $member ??= User::factory()->create();
        $path ??= DataExport::filePrefix($member->id).fake()->unique()->numberBetween(1000, 999999).'.zip';
        $this->file($path, $secondsAgo);

        return DataExport::factory()->for($member)->create([
            'status' => DataExport::STATUS_COMPLETED,
            'file_path' => $path,
            'completed_at' => now()->subSeconds($secondsAgo),
        ]);
    }

    private function prune(): void
    {
        (new PruneDataExports)->handle();
    }

    #[Test]
    public function an_export_whose_window_is_over_loses_its_file_and_keeps_its_row(): void
    {
        $export = $this->export(31 * 60);
        $path = (string) $export->file_path;

        $this->prune();

        Storage::disk('local')->assertMissing($path);
        $export->refresh();
        $this->assertNull($export->file_path, 'the row says there is no file');
        $this->assertSame(DataExport::STATUS_COMPLETED, $export->status, 'the row stays, as the record that the member asked');
        $this->assertNotNull($export->completed_at);
    }

    #[Test]
    public function an_export_still_inside_its_window_keeps_its_file(): void
    {
        $export = $this->export(29 * 60);

        $this->prune();

        Storage::disk('local')->assertExists((string) $export->file_path);
        $this->assertSame($export->file_path, $export->fresh()->file_path);
    }

    #[Test]
    public function the_window_ends_where_the_model_says_it_does(): void
    {
        // The job asks the database what DataExport::hasExpired() asks the clock, so the two must give the same answer at the edge.
        $edge = DataExport::DOWNLOAD_TTL_MINUTES * 60;

        foreach ([$edge - 1, $edge, $edge + 1] as $seconds) {
            $export = $this->export($seconds);
            $expired = $export->hasExpired();

            $this->prune();

            $this->assertSame($expired, $export->fresh()->file_path === null, "at $seconds seconds hasExpired() is ".json_encode($expired).' and the job must agree');
            $this->assertSame($expired, ! Storage::disk('local')->exists((string) $export->file_path));
        }

        $this->assertSame(1800, $edge, 'the member is told 30 minutes');
    }

    #[Test]
    public function more_exports_than_one_chunk_are_all_cleared(): void
    {
        $member = User::factory()->create();

        $ids = [];
        foreach (range(1, 230) as $n) {
            $ids[] = $this->export(2 * 3600, $member, DataExport::filePrefix($member->id)."$n.zip")->id;
        }

        $this->prune();

        $this->assertSame(0, DataExport::query()->whereIn('id', $ids)->whereNotNull('file_path')->count());
        $this->assertSame([], Storage::disk('local')->files('exports'));
    }

    #[Test]
    public function a_file_no_export_names_is_deleted_once_it_is_two_windows_old(): void
    {
        $edge = PruneDataExports::UNNAMED_FILE_MINUTES * 60;

        $old = $this->file('exports/user-7-old.zip', $edge + 60);
        $atTheEdge = $this->file('exports/user-7-edge.zip', $edge);
        $young = $this->file('exports/user-7-young.zip', $edge - 60);

        $this->prune();

        Storage::disk('local')->assertMissing($old);
        Storage::disk('local')->assertExists($atTheEdge);
        Storage::disk('local')->assertExists($young);
        $this->assertSame(3600, $edge, 'two windows');
    }

    #[Test]
    public function the_zip_of_a_member_who_was_deleted_by_an_earlier_version_is_swept(): void
    {
        $member = User::factory()->create();
        $export = $this->export(5 * 60, $member);
        $path = (string) $export->file_path;

        // The account goes the way it did before MemberLeftovers (nothing unlinks the file, which is now done when the account is deleted:
        // see DeletedMemberTest), and the database takes the row with it; the file has nobody to ask for it.
        User::withoutEvents(fn () => $member->delete());
        $this->assertNull(DataExport::query()->find($export->id));
        Storage::disk('local')->assertExists($path);

        $this->travel(2)->hours();
        $this->prune();

        Storage::disk('local')->assertMissing($path);
    }

    #[Test]
    public function a_file_that_an_open_export_names_is_never_taken_even_if_it_is_old(): void
    {
        // A job that took a long time to write its file: the file is older than two windows, the export's window has only just opened.
        $export = $this->export(10 * 60);
        $path = (string) $export->file_path;
        $this->file($path, 3 * 3600);

        $this->prune();

        Storage::disk('local')->assertExists($path);
        $this->assertSame($path, $export->fresh()->file_path);
    }

    #[Test]
    public function exports_that_are_not_finished_are_left_alone(): void
    {
        $pending = DataExport::factory()->create(['status' => DataExport::STATUS_PENDING]);
        $processing = DataExport::factory()->create(['status' => DataExport::STATUS_PROCESSING]);
        $failed = DataExport::factory()->create(['status' => DataExport::STATUS_FAILED, 'failure_reason' => 'It failed.']);

        $this->prune();

        foreach ([$pending, $processing, $failed] as $export) {
            $this->assertSame($export->status, $export->fresh()->status);
        }
        $this->assertSame('It failed.', $failed->fresh()->failure_reason);
    }

    #[Test]
    public function only_the_exports_directory_is_swept(): void
    {
        $this->file('exports/.gitignore', 5 * 86400);
        $this->file('exports/.gitkeep', 5 * 86400);
        $this->file('support-attachments/ticket-1/invoice.pdf', 5 * 86400);
        $this->file('forum-attachments/1/photo.webp', 5 * 86400);
        $this->file('products/file.zip', 5 * 86400);
        $this->file('stray.zip', 5 * 86400);
        $this->file('exports/user-8-old.zip', 5 * 86400);

        $this->prune();

        foreach (['exports/.gitignore', 'exports/.gitkeep', 'support-attachments/ticket-1/invoice.pdf', 'forum-attachments/1/photo.webp', 'products/file.zip', 'stray.zip'] as $kept) {
            Storage::disk('local')->assertExists($kept);
        }
        Storage::disk('local')->assertMissing('exports/user-8-old.zip');
    }

    #[Test]
    public function it_copes_with_no_exports_directory_and_with_being_run_again(): void
    {
        $this->prune();
        $this->prune();

        $export = $this->export(31 * 60);
        $this->prune();
        $this->prune();

        $this->assertNull($export->fresh()->file_path);
        $this->assertSame([], Storage::disk('local')->files('exports'));
    }

    /**
     * What a row can name on a site that upgraded: staff could save any string as the path, and a row made then is still there.
     *
     * @return array<string, array{string}>
     */
    public static function pathsSavedBeforeTheRule(): array
    {
        return [
            'a support attachment' => ['support-attachments/ticket-1/invoice.pdf'],
            'a forum attachment' => ['forum-attachments/12/photo.webp'],
            'a product download' => ['products/12/game.zip'],
            'a file in a folder of the exports directory' => ['exports/2026/user-1.zip'],
            'a way out of the exports directory' => ['exports/../support-attachments/ticket-1/invoice.pdf'],
            'a hidden file of the exports directory' => ['exports/.gitignore'],
            'a file at the root' => ['stray.zip'],
        ];
    }

    /**
     * A row that names another file, and (where the path is a real one) that file with something in it.
     */
    private function legacyExport(string $path, int $secondsAgo): DataExport
    {
        // Where the file really is: the path may read as a way out of exports/, and the file is at the end of it.
        $real = str_contains($path, '..') ? 'support-attachments/ticket-1/invoice.pdf' : $path;
        $this->file($real, 5 * 86400);

        return DataExport::factory()->for(User::factory()->create())->create([
            'status' => DataExport::STATUS_COMPLETED,
            'file_path' => $path,
            'completed_at' => now()->subSeconds($secondsAgo),
        ]);
    }

    #[Test]
    #[DataProvider('pathsSavedBeforeTheRule')]
    public function an_expired_export_that_names_another_file_forgets_it_and_the_file_is_not_deleted(string $path): void
    {
        $export = $this->legacyExport($path, 40 * 60);
        $real = str_contains($path, '..') ? 'support-attachments/ticket-1/invoice.pdf' : $path;

        $this->prune();

        Storage::disk('local')->assertExists($real);
        $this->assertNull($export->fresh()->file_path, 'the row no longer names it');
    }

    #[Test]
    #[DataProvider('pathsSavedBeforeTheRule')]
    public function the_privacy_page_does_not_delete_it_either_and_does_not_offer_it(string $path): void
    {
        $expired = $this->legacyExport($path, 40 * 60);
        $open = $this->legacyExport($path, 5 * 60);
        $real = str_contains($path, '..') ? 'support-attachments/ticket-1/invoice.pdf' : $path;

        $this->assertFalse($expired->isReady());
        $this->assertFalse($open->isReady(), 'an export inside its window is not ready when what it names is not an export');
        Storage::disk('local')->assertExists($real);
    }

    #[Test]
    #[DataProvider('pathsSavedBeforeTheRule')]
    public function the_download_link_does_not_serve_a_file_that_is_not_an_export(string $path): void
    {
        $open = $this->legacyExport($path, 5 * 60);

        $this->actingAs($open->user)
            ->get(URL::temporarySignedRoute('privacy.exports.download', now()->addMinutes(5), ['export' => $open->id]))
            ->assertNotFound();
    }

    #[Test]
    public function a_row_that_names_another_members_export_does_not_offer_serve_or_delete_it(): void
    {
        $theirs = $this->export(5 * 60);
        $path = (string) $theirs->file_path;

        // Saved by staff before a path had to be the member's own: one still inside its window, one past it.
        $other = User::factory()->create();
        $open = DataExport::factory()->for($other)->completed()->create(['file_path' => $path, 'completed_at' => now()->subMinutes(5)]);
        $over = DataExport::factory()->for($other)->completed()->create(['file_path' => $path, 'completed_at' => now()->subMinutes(40)]);

        $this->actingAs($other)
            ->get(URL::temporarySignedRoute('privacy.exports.download', now()->addMinutes(5), ['export' => $open->id]))
            ->assertNotFound();
        $this->actingAs($other)->get(route('privacy.index'))->assertInertia(fn (Assert $page) => $page
            ->has('exports', 2)
            ->where('exports.0.download_url', null)
            ->where('exports.1.download_url', null));

        $this->prune();

        Storage::disk('local')->assertExists($path);
        $this->assertNull($over->fresh()->file_path, 'the expired row no longer names it');
        $this->assertTrue($theirs->fresh()->isReady(), 'and the member whose export it is still has it');
    }

    #[Test]
    public function the_pattern_is_the_one_staff_are_held_to_and_a_real_export_matches_it(): void
    {
        $this->assertSame(1, preg_match(DataExport::pathPattern(4), 'exports/user-4-20261116_120000.zip'));
        $this->assertSame(0, preg_match(DataExport::pathPattern(4), "exports/user-4-1.zip\n"));

        $named = fn (int $member, ?string $path): bool => (new DataExport(['user_id' => $member, 'file_path' => $path]))->namesAnExportFile();

        $this->assertTrue($named(4, 'exports/user-4-20261116_120000.zip'));
        $this->assertFalse($named(5, 'exports/user-4-20261116_120000.zip'), 'another member\'s');
        $this->assertFalse($named(4, null));
        $this->assertFalse($named(4, 'products/12/game.zip'));
    }

    #[Test]
    public function an_export_that_has_no_file_is_not_even_read(): void
    {
        // Every export ever finished stays as a row with no file, so a job that read them all each quarter of an hour would read more
        // each year; the database is asked only for the ones that still name a file.
        DataExport::factory()->count(3)->create([
            'status' => DataExport::STATUS_COMPLETED,
            'file_path' => null,
            'completed_at' => now()->subDays(40),
        ]);
        $waiting = $this->export(31 * 60);

        $read = [];
        DataExport::retrieved(function (DataExport $export) use (&$read): void {
            $read[] = $export->id;
        });

        $this->prune();

        $this->assertSame([$waiting->id], array_values(array_unique($read)), 'only the export that still names a file was read by the first pass');
    }

    #[Test]
    public function a_row_whose_file_is_already_gone_is_cleared_too(): void
    {
        $export = $this->export(40 * 60);
        Storage::disk('local')->delete((string) $export->file_path);

        $this->prune();

        $this->assertNull($export->fresh()->file_path);
    }

    #[Test]
    public function the_job_is_scheduled_every_fifteen_minutes(): void
    {
        $this->artisan('schedule:list')->assertSuccessful();

        $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains((string) $event->description, 'PruneDataExports') || str_contains($event->command ?? '', 'PruneDataExports'));

        $this->assertCount(1, $events);
        $this->assertSame('*/15 * * * *', $events->first()->expression);
    }

    #[Test]
    public function the_job_that_makes_an_export_writes_into_the_directory_the_job_that_sweeps_reads(): void
    {
        $member = User::factory()->create();
        $export = DataExport::factory()->for($member)->create();

        Notification::fake();
        (new GenerateUserDataExport($export->id))->handle();

        $this->assertStringStartsWith(DataExport::DIRECTORY."/user-{$member->id}-", (string) $export->fresh()->file_path);
        Storage::disk('local')->assertExists((string) $export->fresh()->file_path);
        $this->assertTrue($export->fresh()->isReady(), 'what the job names is what the member may download');
        $this->assertSame('exports', DataExport::DIRECTORY);
    }
}
