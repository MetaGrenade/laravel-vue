<?php

namespace Tests\Feature\Usage;

use App\Jobs\GenerateUserDataExport;
use App\Models\DataExport;
use App\Models\SupportTicket;
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
 * Usage is the account's own data, so a data export includes it, in both of the files the archive has: the JSON and the CSV.
 */
class UsageExportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Both files of the archive.
     *
     * @return array{json: array<string, mixed>, csv: list<list<string|null>>}
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
        $csv = $zip->getFromName('export.csv');
        $zip->close();

        return [
            'json' => json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR),
            'csv' => array_map('str_getcsv', array_filter(explode("\n", (string) $csv), fn (string $line) => trim($line) !== '')),
        ];
    }

    /**
     * The usage rows of the CSV.
     *
     * @param  list<list<string|null>>  $csv
     * @return list<list<string|null>>
     */
    private function usageRows(array $csv): array
    {
        return array_values(array_filter($csv, fn (array $row) => $row[0] === 'usage'));
    }

    #[Test]
    public function the_export_has_what_the_account_used_a_day_and_a_meter_in_the_json(): void
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
        ], $this->export($user)['json']['usage']);
    }

    #[Test]
    public function and_the_same_rows_are_in_the_csv(): void
    {
        $usage = app(Usage::class);
        $user = User::factory()->create();

        $usage->record($user, 'api-calls', 2, at: CarbonImmutable::parse('2026-10-01 08:00:00'));
        $usage->record($user, 'api-calls', 3, at: CarbonImmutable::parse('2026-10-01 20:00:00'));
        $usage->record($user, 'api-calls', 4, at: CarbonImmutable::parse('2026-10-02 10:00:00'));
        $usage->record(User::factory()->create(), 'api-calls', 99, at: CarbonImmutable::parse('2026-10-01 10:00:00'));

        $csv = $this->export($user)['csv'];

        $this->assertSame(['resource_type', 'resource_id', 'summary', 'created_at', 'updated_at'], $csv[0]);
        $this->assertSame([
            ['usage', 'api-calls:2026-10-01', '5 api-calls used', '2026-10-01', '2026-10-01'],
            ['usage', 'api-calls:2026-10-02', '4 api-calls used', '2026-10-02', '2026-10-02'],
        ], $this->usageRows($csv));
    }

    #[Test]
    public function the_csv_keeps_what_else_it_had_beside_the_usage(): void
    {
        $user = User::factory()->create();
        SupportTicket::factory()->for($user)->create(['subject' => 'Help me']);
        app(Usage::class)->record($user, 'api-calls', 1, at: CarbonImmutable::parse('2026-10-01 08:00:00'));

        $csv = $this->export($user)['csv'];

        $this->assertSame(['support_ticket', 'usage'], array_values(array_unique(array_column(array_slice($csv, 1), 0))));
    }

    #[Test]
    public function an_account_that_used_nothing_has_no_usage_in_either_file(): void
    {
        $export = $this->export(User::factory()->create());

        $this->assertSame([], $export['json']['usage']);
        $this->assertSame([], $this->usageRows($export['csv']));
    }
}
