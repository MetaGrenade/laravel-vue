<?php

namespace Tests\Feature\Admin;

use App\Models\DataExport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Staff can mark an export finished and say which file it is (Admin → Trust & safety). The member downloads whatever that names, and
 * PruneDataExports deletes it when the window is over, so it has to be that member's own export file. Before, any path on the private
 * disk was accepted, and later any file of the exports directory: a member of staff could have somebody else's export, a support
 * attachment or a digital product handed to them as their own "export", and then have it deleted.
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
     * `{id}` stands for the number of the member the export is for, so that each of these is wrong for the reason it names and not only
     * for not being theirs.
     *
     * @return array<string, array{string}>
     */
    public static function pathsThatAreNotTheMembersExport(): array
    {
        return [
            'a support attachment' => ['support-attachments/ticket-1/invoice.pdf'],
            'a forum attachment' => ['forum-attachments/12/photo.webp'],
            'a digital product' => ['products/12/game.zip'],
            'the directory itself' => ['exports'],
            'nothing after the slash' => ['exports/'],
            'a file named for nobody' => ['exports/export.zip'],
            'the name with no dash after the number' => ['exports/user-{id}.zip'],
            'nothing after the dash' => ['exports/user-{id}-'],
            'a file in a folder of it' => ['exports/user-{id}-2026/export.zip'],
            'a way out of it' => ['exports/user-{id}-/../../support-attachments/ticket-1/invoice.pdf'],
            'the folder above' => ['exports/..'],
            'a hidden file' => ['exports/.user-{id}-1.zip'],
            'another disk root' => ['/exports/user-{id}-1.zip'],
            'the same start in other letters' => ['Exports/user-{id}-1.zip'],
            'the name in other letters' => ['exports/User-{id}-1.zip'],
            'a name that merely starts alike' => ['exports-old/user-{id}-1.zip'],
            'the directory further down' => ['support-attachments/exports/user-{id}-1.zip'],
            'a name with a space' => ['exports/user-{id}- 1.zip'],
        ];
    }

    #[Test]
    #[DataProvider('pathsThatAreNotTheMembersExport')]
    public function a_path_that_is_not_the_members_own_export_is_refused(string $path): void
    {
        $agent = $this->agent();
        $export = DataExport::factory()->for($agent)->create(['status' => DataExport::STATUS_PENDING]);
        $path = str_replace('{id}', (string) $agent->id, $path);

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
    public function the_members_own_export_file_is_accepted(): void
    {
        $export = DataExport::factory()->create(['status' => DataExport::STATUS_PENDING]);
        $path = "exports/user-{$export->user_id}-20261116_120000.zip";

        $this->actingAs($this->agent())
            ->patch(route('acp.trust-safety.exports.update', $export), [
                'status' => DataExport::STATUS_COMPLETED,
                'file_path' => $path,
                'completed_at' => '2026-11-16T12:00',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($path, $export->fresh()->file_path);
    }

    #[Test]
    public function an_export_cannot_be_pointed_at_the_file_of_a_member_whose_number_begins_alike(): void
    {
        $agent = $this->agent();
        $short = DataExport::factory()->for(User::factory()->create(['id' => 501]))->create();
        $long = DataExport::factory()->for(User::factory()->create(['id' => 5012]))->create();

        foreach ([[$short, 'exports/user-5012-1.zip', 'exports/user-501-'], [$long, 'exports/user-501-2.zip', 'exports/user-5012-']] as [$export, $path, $own]) {
            $this->actingAs($agent)
                ->patch(route('acp.trust-safety.exports.update', $export), ['status' => DataExport::STATUS_COMPLETED, 'file_path' => $path])
                // The refusal says whose file it has to be.
                ->assertSessionHasErrors(['file_path' => "The path has to name this member's own export file, starting with {$own}."]);

            $this->assertNull($export->fresh()->file_path);
        }
    }

    #[Test]
    public function staff_cannot_have_another_members_export_handed_to_them(): void
    {
        $agent = $this->agent();
        $member = User::factory()->create();
        $theirs = "exports/user-{$member->id}-20261116_120000.zip";
        Storage::disk('local')->put($theirs, 'everything the site holds about the member');
        DataExport::factory()->for($member)->completed()->create(['file_path' => $theirs]);
        $own = DataExport::factory()->for($agent)->create();

        $this->actingAs($agent)
            ->patch(route('acp.trust-safety.exports.update', $own), [
                'status' => DataExport::STATUS_COMPLETED,
                'file_path' => $theirs,
                'completed_at' => now()->format('Y-m-d\TH:i'),
            ])
            ->assertSessionHasErrors('file_path');

        $this->actingAs($agent)
            ->get(URL::temporarySignedRoute('privacy.exports.download', now()->addMinutes(5), ['export' => $own->id]))
            ->assertNotFound();
        $this->assertNull($own->fresh()->file_path);
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
    public function a_line_break_after_a_good_name_does_not_get_through_the_end_of_the_pattern(): void
    {
        // `$` alone matches before a final line break. (A request's text is trimmed before it gets here; the rule does not depend on that.)
        $rules = ['file_path' => ['regex:'.DataExport::pathPattern(1)]];

        $this->assertTrue(Validator::make(['file_path' => 'exports/user-1-1.zip'], $rules)->passes());
        $this->assertFalse(Validator::make(['file_path' => "exports/user-1-1.zip\n"], $rules)->passes());
    }
}
