<?php

namespace Tests\Feature\Settings;

use App\Jobs\GenerateUserDataExport;
use App\Models\Blog;
use App\Models\BlogComment;
use App\Models\DataExport;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;
use ZipArchive;

class DataExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_request_data_export(): void
    {
        $this->post(route('privacy.exports.store'))
            ->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_request_data_export(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('privacy.exports.store'));

        $response->assertRedirect(route('privacy.index'));

        $this->assertDatabaseHas('data_exports', [
            'user_id' => $user->id,
            'status' => DataExport::STATUS_PENDING,
        ]);

        Queue::assertPushed(GenerateUserDataExport::class, function (GenerateUserDataExport $job) use ($user) {
            $this->assertDatabaseHas('data_exports', [
                'id' => $job->exportId,
                'user_id' => $user->id,
            ]);

            return true;
        });
    }

    public function test_duplicate_export_requests_are_blocked(): void
    {
        $user = User::factory()->create();

        $user->dataExports()->create([
            'status' => DataExport::STATUS_PENDING,
        ]);

        $response = $this->actingAs($user)->post(route('privacy.exports.store'));

        $response->assertRedirect(route('privacy.index'));
        $response->assertSessionHasErrors('export');
    }

    public function test_signed_download_requires_authorized_user(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        /** @var DataExport $export */
        $export = DataExport::factory()
            ->for($user)
            ->completed()
            ->create([
                'file_path' => 'exports/test.zip',
            ]);

        Storage::disk('local')->put($export->file_path, 'contents');

        $signedUrl = URL::temporarySignedRoute(
            'privacy.exports.download',
            now()->addMinutes(10),
            ['export' => $export->id]
        );

        $this->actingAs($user)
            ->get($signedUrl)
            ->assertOk();

        $this->actingAs($otherUser)
            ->get($signedUrl)
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('privacy.exports.download', $export))
            ->assertForbidden();
    }

    public function test_generate_user_data_export_job_creates_archive(): void
    {
        Storage::fake('local');

        $user = User::factory()->create([
            'nickname' => 'Download Tester',
        ]);

        $blog = Blog::factory()->for($user)->create();
        BlogComment::factory()->for($blog)->for($user)->create();
        SupportTicket::factory()->for($user)->create();

        $export = DataExport::factory()->for($user)->create();

        $job = new GenerateUserDataExport($export->id);
        $job->handle();

        $export->refresh();

        $this->assertEquals(DataExport::STATUS_COMPLETED, $export->status);
        $this->assertNotNull($export->file_path);
        $this->assertNotNull($export->completed_at);
        $this->assertTrue(Storage::disk('local')->exists($export->file_path));

        $zip = new ZipArchive();
        $this->assertTrue($zip->open(Storage::disk('local')->path($export->file_path)) === true);

        $json = $zip->getFromName('export.json');
        $csv = $zip->getFromName('export.csv');
        $zip->close();

        $this->assertNotFalse($json);
        $this->assertNotFalse($csv);

        $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($user->email, $payload['user']['email']);
        $this->assertCount(1, $payload['blog_comments']);
        $this->assertCount(1, $payload['support_tickets']);
        $this->assertStringContainsString('resource_type', $csv);
    }
}
