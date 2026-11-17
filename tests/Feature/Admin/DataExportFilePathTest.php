<?php

namespace Tests\Feature\Admin;

use App\Http\Requests\Admin\UpdateDataExportRequest;
use App\Models\DataExport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Staff can mark an export finished and say which file it is (Admin → Trust & safety). The member downloads whatever that names, and
 * PruneDataExports deletes it when the window is over, so it has to be a file in the exports directory: before, any path on the
 * private disk was accepted, and a member of staff could have a support attachment, a forum attachment or a digital product handed to
 * them as their own "export", and then have it deleted.
 */
class DataExportFilePathTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        Role::create(['name' => 'moderator', 'guard_name' => 'web']);
        Permission::create(['name' => 'trust_safety.acp.view', 'guard_name' => 'web']);
        Permission::create(['name' => 'trust_safety.acp.edit', 'guard_name' => 'web']);
    }

    private function agent(): User
    {
        $user = User::factory()->create();
        $user->assignRole('moderator');
        $user->givePermissionTo(['trust_safety.acp.view', 'trust_safety.acp.edit']);

        return $user;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function pathsThatAreNotExports(): array
    {
        return [
            'a support attachment' => ['support-attachments/ticket-1/invoice.pdf'],
            'a forum attachment' => ['forum-attachments/12/photo.webp'],
            'a digital product' => ['products/12/game.zip'],
            'the directory itself' => ['exports'],
            'nothing after the slash' => ['exports/'],
            'a file in a folder of it' => ['exports/2026/user-1.zip'],
            'a way out of it' => ['exports/../support-attachments/ticket-1/invoice.pdf'],
            'the folder above' => ['exports/..'],
            'a hidden file' => ['exports/.gitignore'],
            'another disk root' => ['/etc/passwd'],
            'the same start in other letters' => ['Exports/user-1.zip'],
            'a name that merely starts alike' => ['exports-old/user-1.zip'],
            'the directory further down' => ['support-attachments/exports/user-1.zip'],
            'a name with a space' => ['exports/user 1.zip'],
        ];
    }

    #[Test]
    #[DataProvider('pathsThatAreNotExports')]
    public function a_path_outside_the_exports_directory_is_refused(string $path): void
    {
        $agent = $this->agent();
        $export = DataExport::factory()->for($agent)->create(['status' => DataExport::STATUS_PENDING]);

        $this->actingAs($agent)
            ->patch(route('acp.trust-safety.exports.update', $export), [
                'status' => DataExport::STATUS_COMPLETED,
                'file_path' => $path,
                'completed_at' => '2026-11-16T12:00',
            ])
            ->assertSessionHasErrors('file_path');

        $export->refresh();
        $this->assertSame(DataExport::STATUS_PENDING, $export->status, 'nothing was changed');
        $this->assertNull($export->file_path);
    }

    #[Test]
    public function a_file_in_the_exports_directory_is_accepted(): void
    {
        $export = DataExport::factory()->create(['status' => DataExport::STATUS_PENDING]);

        $this->actingAs($this->agent())
            ->patch(route('acp.trust-safety.exports.update', $export), [
                'status' => DataExport::STATUS_COMPLETED,
                'file_path' => 'exports/user-4-20261116_120000.zip',
                'completed_at' => '2026-11-16T12:00',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('exports/user-4-20261116_120000.zip', $export->fresh()->file_path);
    }

    #[Test]
    public function an_export_with_no_file_may_be_marked_failed_without_one(): void
    {
        $export = DataExport::factory()->create(['status' => DataExport::STATUS_PROCESSING]);

        $this->actingAs($this->agent())
            ->patch(route('acp.trust-safety.exports.update', $export), [
                'status' => DataExport::STATUS_FAILED,
                'file_path' => '',
                'failure_reason' => 'The worker was stopped.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(DataExport::STATUS_FAILED, $export->fresh()->status);
        $this->assertNull($export->fresh()->file_path);
    }

    #[Test]
    public function the_directory_in_the_rule_is_the_one_the_export_job_writes_to(): void
    {
        $this->assertSame('exports', DataExport::DIRECTORY);

        $rules = ['file_path' => (new UpdateDataExportRequest)->rules()['file_path']];

        $this->assertTrue(Validator::make(['file_path' => DataExport::DIRECTORY.'/user-1-20261116_120000.zip'], $rules)->passes());
    }

    #[Test]
    public function a_line_break_after_a_good_name_does_not_get_through_the_end_of_the_pattern(): void
    {
        // `$` alone matches before a final line break. (A request's text is trimmed before it gets here; the rule does not depend on that.)
        $rules = ['file_path' => (new UpdateDataExportRequest)->rules()['file_path']];

        $this->assertFalse(Validator::make(['file_path' => "exports/user-1.zip\n"], $rules)->passes());
    }
}
