<?php

namespace Tests\Support;

use App\Jobs\GenerateUserDataExport;
use App\Models\DataExport;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Run a member's data export as the queue would and read the two files out of the zip, which is what the member gets.
 */
trait ReadsDataExport
{
    /**
     * @param  (Closure(int): GenerateUserDataExport)|null  $makeJob  how to make the job for the export's id, for a test that needs it made in a certain way
     * @return array{json: string, csv: string, export: DataExport}
     */
    protected function dataExportOf(User $user, ?Closure $makeJob = null): array
    {
        Storage::fake('local');
        Notification::fake();

        $export = DataExport::factory()->for($user)->create();
        ($makeJob === null ? new GenerateUserDataExport($export->id) : $makeJob($export->id))->handle();

        $export = $export->fresh();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path((string) $export->file_path)) === true, 'The export is a zip.');
        $json = $zip->getFromName('export.json');
        $csv = $zip->getFromName('export.csv');
        $zip->close();

        $this->assertIsString($json, 'The zip has export.json.');
        $this->assertIsString($csv, 'The zip has export.csv.');

        return ['json' => $json, 'csv' => $csv, 'export' => $export];
    }

    /**
     * The `export.json` of a member's data export, decoded.
     *
     * @return array<string, mixed>
     */
    protected function dataExportJson(User $user): array
    {
        return json_decode($this->dataExportOf($user)['json'], true, flags: JSON_THROW_ON_ERROR);
    }
}
