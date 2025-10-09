<?php

namespace Tests\Feature\Settings;

use App\Jobs\GenerateUserDataExport;
use App\Models\Blog;
use App\Models\BlogComment;
use App\Models\DataExport;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\UserDataExportReady;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
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
        Notification::fake();

        $now = Carbon::parse('2024-01-02 03:04:05');
        Carbon::setTestNow($now);

        $user = User::factory()->create([
            'nickname' => 'Download Tester',
        ]);

        $blog = Blog::factory()->for($user)->create();
        BlogComment::factory()->for($blog)->for($user)->create();
        SupportTicket::factory()->for($user)->create();

        $export = DataExport::factory()->for($user)->create();

        $job = new GenerateUserDataExport($export->id);
        $job->handle();

        Carbon::setTestNow();

        $export->refresh();

        $this->assertEquals(DataExport::STATUS_COMPLETED, $export->status);
        $this->assertNotNull($export->file_path);
        $this->assertNotNull($export->completed_at);
        $this->assertTrue(Storage::disk('local')->exists($export->file_path));

        Notification::assertSentToTimes($user, UserDataExportReady::class, 2);

        $expectedExpiry = $now->copy()->addMinutes(DataExport::DOWNLOAD_TTL_MINUTES)->toIso8601String();

        Notification::assertSentTo($user, UserDataExportReady::class, function (UserDataExportReady $notification, array $channels) use ($user, $export, $expectedExpiry) {
            if ($channels !== ['database']) {
                return false;
            }

            $data = $notification->toArray($user);

            $this->assertSame($export->id, $data['export_id']);
            $this->assertSame('Your data export is ready', $data['title']);
            $this->assertSame($data['title'], $data['thread_title']);
            $this->assertSame(route('privacy.index'), $data['url']);
            $this->assertNotNull($data['download_url']);
            $this->assertStringContainsString('settings/privacy/exports/' . $export->id . '/download', $data['download_url']);
            $this->assertSame($expectedExpiry, $data['download_expires_at']);

            return true;
        });

        Notification::assertSentTo($user, UserDataExportReady::class, function (UserDataExportReady $notification, array $channels) use ($user, $export) {
            if ($channels !== ['mail']) {
                return false;
            }

            $mailMessage = $notification->toMail($user);

            $this->assertSame('Your data export is ready', $mailMessage->subject);
            $this->assertStringContainsString('settings/privacy/exports/' . $export->id . '/download', $mailMessage->actionUrl);

            return true;
        });

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

    public function test_generate_user_data_export_job_honors_notification_preferences(): void
    {
        Storage::fake('local');
        Notification::fake();

        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $user->notificationSettings()->create([
            'category' => 'privacy',
            'channel_mail' => false,
            'channel_push' => true,
            'channel_database' => true,
        ]);

        $export = DataExport::factory()->for($user)->create();

        $job = new GenerateUserDataExport($export->id);
        $job->handle();

        Notification::assertSentToTimes($user, UserDataExportReady::class, 1);

        Notification::assertSentTo($user, UserDataExportReady::class, function (UserDataExportReady $notification, array $channels) {
            return $channels === ['database'];
        });

        Notification::assertNotSentTo($user, UserDataExportReady::class, function (UserDataExportReady $notification, array $channels) {
            return in_array('mail', $channels, true);
        });
    }

    public function test_generate_user_data_export_job_skips_disabled_channels(): void
    {
        Storage::fake('local');
        Notification::fake();

        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $user->notificationSettings()->create([
            'category' => 'privacy',
            'channel_mail' => false,
            'channel_push' => false,
            'channel_database' => false,
        ]);

        $export = DataExport::factory()->for($user)->create();

        $job = new GenerateUserDataExport($export->id);
        $job->handle();

        Notification::assertNothingSent();
    }

    public function test_completed_exports_expire_after_ttl(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();

        /** @var DataExport $export */
        $export = DataExport::factory()
            ->for($user)
            ->completed()
            ->create([
                'file_path' => 'exports/expired.zip',
                'completed_at' => now()->subMinutes(DataExport::DOWNLOAD_TTL_MINUTES + 1),
            ]);

        Storage::disk('local')->put($export->file_path, 'contents');

        $response = $this->actingAs($user)->get(route('privacy.index'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('settings/Privacy')
            ->where('exports.0.download_url', null)
        );

        Storage::disk('local')->assertMissing('exports/expired.zip');

        $this->assertDatabaseHas('data_exports', [
            'id' => $export->id,
            'file_path' => null,
        ]);
    }

    public function test_download_after_ttl_is_rejected_and_file_removed(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();

        /** @var DataExport $export */
        $export = DataExport::factory()
            ->for($user)
            ->completed()
            ->create([
                'file_path' => 'exports/expired.zip',
                'completed_at' => now()->subMinutes(DataExport::DOWNLOAD_TTL_MINUTES + 1),
            ]);

        Storage::disk('local')->put($export->file_path, 'contents');

        $signedUrl = URL::temporarySignedRoute(
            'privacy.exports.download',
            now()->addMinutes(5),
            ['export' => $export->id]
        );

        $this->actingAs($user)
            ->get($signedUrl)
            ->assertStatus(410);

        Storage::disk('local')->assertMissing('exports/expired.zip');

        $this->assertDatabaseHas('data_exports', [
            'id' => $export->id,
            'file_path' => null,
        ]);
    }

    public function test_generate_user_data_export_respects_notification_preferences(): void
    {
        Storage::fake('local');
        Notification::fake();

        $user = User::factory()->create(['email_verified_at' => now()]);

        $user->notificationSettings()->create([
            'category' => 'privacy',
            'channel_mail' => true,
            'channel_push' => false,
            'channel_database' => false,
        ]);

        $export = DataExport::factory()->for($user)->create();

        (new GenerateUserDataExport($export->id))->handle();

        Notification::assertSentToTimes($user, UserDataExportReady::class, 1);

        Notification::assertSentTo($user, UserDataExportReady::class, function (UserDataExportReady $notification, array $channels) use ($user) {
            if ($channels !== ['mail']) {
                return false;
            }

            $mailMessage = $notification->toMail($user);

            return $mailMessage->subject === 'Your data export is ready';
        });

        Notification::assertNotSentTo($user, UserDataExportReady::class, function (UserDataExportReady $notification, array $channels) {
            return in_array('database', $channels, true);
        });
    }
}
