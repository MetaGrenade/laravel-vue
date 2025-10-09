<?php

namespace App\Notifications;

use App\Models\DataExport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class UserDataExportReady extends Notification implements ShouldQueue
{
    use Queueable;

    protected ?string $downloadUrl = null;

    /**
     * @param  array<int, string>  $channels
     */
    public function __construct(
        protected DataExport $export,
        protected array $channels = ['mail', 'database'],
    ) {
        $this->downloadUrl = $this->generateDownloadUrl();
    }

    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    public function viaQueues(): array
    {
        return [
            'mail' => 'mail',
            'database' => 'default',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $greeting = 'Hi ' . ($notifiable->nickname ?? $notifiable->name ?? 'there') . '!';
        $downloadUrl = $this->downloadUrl();

        $mailMessage = (new MailMessage())
            ->subject('Your data export is ready')
            ->greeting($greeting)
            ->line('Your privacy data export is ready to download.')
            ->line('The archive contains your profile details, blog comments, forum posts, and support tickets.');

        if ($downloadUrl) {
            $mailMessage->action('Download export', $downloadUrl);
        } else {
            $mailMessage->action('View privacy settings', route('privacy.index'));
        }

        $mailMessage->line('This download link will expire in ' . DataExport::DOWNLOAD_TTL_MINUTES . ' minutes.')
            ->line('If you did not request this export, please contact support.');

        return $mailMessage;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'export_id' => $this->export->id,
            'status' => $this->export->status,
            'title' => 'Your data export is ready',
            'thread_title' => 'Your data export is ready',
            'excerpt' => 'Download your privacy data export before it expires.',
            'url' => route('privacy.index'),
            'download_url' => $this->downloadUrl(),
            'download_expires_at' => $this->export->downloadExpiresAt()?->toIso8601String(),
        ];
    }

    /**
     * @param  array<int, string>  $channels
     */
    public function withChannels(array $channels): self
    {
        $clone = clone $this;
        $clone->channels = $channels;

        return $clone;
    }

    protected function downloadUrl(): ?string
    {
        return $this->downloadUrl;
    }

    protected function generateDownloadUrl(): ?string
    {
        if (! $this->export->file_path || $this->export->hasExpired()) {
            return null;
        }

        $expiresAt = $this->export->downloadExpiresAt() ?? now()->addMinutes(DataExport::DOWNLOAD_TTL_MINUTES);

        return URL::temporarySignedRoute(
            'privacy.exports.download',
            $expiresAt,
            ['export' => $this->export->id]
        );
    }
}
