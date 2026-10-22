<?php

namespace Tests\Feature\Usage;

use App\Jobs\GenerateUserDataExport;
use App\Models\DataExport;
use App\Models\User;
use App\Support\Usage\Usage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

/**
 * Usage is the account's own data, so a data export includes it.
 */
class UsageExportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function export(User $user): array
    {
        Storage::fake('local');
        Notification::fake();

        $export = DataExport::factory()->for($user)->create();
        (new GenerateUserDataExport($export->id))->handle();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($export->fresh()->file_path)) === true);
        $json = $zip->getFromName('export.json');
        $zip->close();

        return json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function the_export_has_what_the_account_used_a_day_and_a_meter(): void
    {
        $usage = app(Usage::class);
        $user = User::factory()->create();

        $usage->record($user, 'api-calls', 2, at: CarbonImmutable::parse('2026-10-01 08:00:00'));
        $usage->record($user, 'api-calls', 3, at: CarbonImmutable::parse('2026-10-01 20:00:00'));
        $usage->record($user, 'api-calls', 4, at: CarbonImmutable::parse('2026-10-02 10:00:00'));
        $usage->record(User::factory()->create(), 'api-calls', 99, at: CarbonImmutable::parse('2026-10-01 10:00:00'));

        $this->assertSame([
            ['meter' => 'api-calls', 'date' => '2026-10-01', 'units' => 5],
            ['meter' => 'api-calls', 'date' => '2026-10-02', 'units' => 4],
        ], $this->export($user)['usage']);
    }

    #[Test]
    public function an_account_that_used_nothing_has_an_empty_list(): void
    {
        $this->assertSame([], $this->export(User::factory()->create())['usage']);
    }
}
