<?php

namespace Tests\Feature\Settings;

use App\Jobs\GenerateUserDataExport;
use App\Models\DataExport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

/**
 * A person's API keys are their own account data, so a data export lists them, without the secret, in both files of the archive.
 */
class ApiKeysExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00'));
    }

    /**
     * @return array{json: array<string, mixed>, csv: list<list<string|null>>, raw: string}
     */
    private function export(User $user): array
    {
        Storage::fake('local');
        Notification::fake();

        $export = DataExport::factory()->for($user)->create();
        (new GenerateUserDataExport($export->id))->handle();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($export->fresh()->file_path)) === true);
        $json = (string) $zip->getFromName('export.json');
        $csv = (string) $zip->getFromName('export.csv');
        $zip->close();

        return [
            'json' => json_decode($json, true, flags: JSON_THROW_ON_ERROR),
            'csv' => array_map('str_getcsv', array_filter(explode("\n", $csv), fn (string $line) => trim($line) !== '')),
            'raw' => $json.$csv,
        ];
    }

    #[Test]
    public function the_export_lists_the_keys_the_person_made_whatever_has_become_of_them(): void
    {
        $user = User::factory()->create();
        $live = $user->createToken('CI', ['profile:read', 'polls:vote'])->accessToken;
        $live->forceFill(['created_at' => now()->subDays(3), 'last_used_at' => now()->subHour(), 'expires_at' => now()->addDays(27)])->save();
        $revoked = $user->createToken('Old laptop', ['*'])->accessToken;
        $revoked->forceFill(['created_at' => now()->subDays(30), 'revoked_at' => now()->subDays(2)])->save();
        $user->createToken('Somebody else\'s', ['*']);
        User::factory()->create()->createToken('Not theirs', ['*']);

        $keys = $this->export($user)['json']['api_keys'];

        $this->assertCount(3, $keys);
        $this->assertSame(['Old laptop', 'CI', 'Somebody else\'s'], array_column($keys, 'name'));
        $this->assertEquals([
            'id' => $live->id,
            'name' => 'CI',
            'scopes' => ['profile:read', 'polls:vote'],
            'created_at' => '2026-10-12T12:00:00+00:00',
            'last_used_at' => '2026-10-15T11:00:00+00:00',
            'expires_at' => '2026-11-11T12:00:00+00:00',
            'revoked_at' => null,
        ], $keys[1]);
        $this->assertSame('2026-10-13T12:00:00+00:00', $keys[0]['revoked_at']);
    }

    #[Test]
    public function the_export_never_holds_the_secret_or_its_hash(): void
    {
        $user = User::factory()->create();
        $key = $user->createToken('CI', ['profile:read']);

        $export = $this->export($user);

        $this->assertStringNotContainsString(explode('|', $key->plainTextToken)[1], $export['raw']);
        $this->assertStringNotContainsString($key->accessToken->getRawOriginal('token'), $export['raw']);
        $this->assertArrayNotHasKey('token', $export['json']['api_keys'][0]);
    }

    #[Test]
    public function the_csv_has_a_row_for_each_key(): void
    {
        $user = User::factory()->create();
        $live = $user->createToken('CI', ['profile:read', 'polls:vote'])->accessToken;
        $live->forceFill(['created_at' => now()->subDays(3), 'last_used_at' => now()->subHour()])->save();
        $unused = $user->createToken('Unused', ['*'])->accessToken;
        $unused->forceFill(['created_at' => now()->subDays(2)])->save();
        $revoked = $user->createToken('Gone', ['*'])->accessToken;
        $revoked->forceFill(['created_at' => now()->subDay(), 'revoked_at' => now()->subMinutes(10), 'last_used_at' => now()->subHours(5)])->save();

        $rows = array_values(array_filter($this->export($user)['csv'], fn (array $row) => $row[0] === 'api_key'));

        $this->assertSame([
            ['api_key', (string) $live->id, 'CI: profile:read polls:vote', '2026-10-12T12:00:00+00:00', '2026-10-15T11:00:00+00:00'],
            ['api_key', (string) $unused->id, 'Unused: *', '2026-10-13T12:00:00+00:00', '2026-10-13T12:00:00+00:00'],
            ['api_key', (string) $revoked->id, 'Gone: *', '2026-10-14T12:00:00+00:00', '2026-10-15T11:50:00+00:00'],
        ], $rows);
    }

    #[Test]
    public function an_account_with_no_keys_has_none_in_either_file(): void
    {
        $export = $this->export(User::factory()->create());

        $this->assertSame([], $export['json']['api_keys']);
        $this->assertSame([], array_values(array_filter($export['csv'], fn (array $row) => $row[0] === 'api_key')));
    }
}
