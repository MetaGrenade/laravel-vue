<?php

namespace App\Notifications;

use App\Models\DataExport;
use App\Notifications\Concerns\SendsBroadcastsSynchronously;
use App\Support\Mail\MailText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class UserDataExportReady extends Notification implements ShouldQueue
{
    use Queueable;
    use SendsBroadcastsSynchronously;

    protected ?string $downloadUrl = null;

    /**
     * @param  array<int, string>  $channels
     */
    public function __construct(
        protected DataExport $export,
        protected array $channels = ['mail', 'database', 'push'],
    ) {
        $this->downloadUrl = $this->generateDownloadUrl();
    }

    public function via(object $notifiable): array
    {
        return array_map(
            static fn (string $channel) => $channel === 'push' ? 'broadcast' : $channel,
            $this->channels,
        );
    }

    public function viaQueues(): array
    {
        return [
            'mail' => 'mail',
            'database' => 'default',
            'broadcast' => 'default',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $greeting = MailText::greeting($notifiable);
        $downloadUrl = $this->downloadUrl();

        $mailMessage = (new MailMessage)
            ->subject(__('mail.data_export.title'))
            ->greeting($greeting)
            ->line(__('mail.data_export.ready'))
            ->line(__('mail.data_export.contents'));

        if ($downloadUrl) {
            $mailMessage->action(__('mail.data_export.download'), $downloadUrl);
        } else {
            $mailMessage->action(__('mail.data_export.view_settings'), route('privacy.index'));
        }

        $mailMessage->line(trans_choice('mail.data_export.expires', DataExport::DOWNLOAD_TTL_MINUTES))
            ->line(__('mail.data_export.if_not_you'));

        return $mailMessage;
    }

    public function toArray(object $notifiable): array
    {
        return $this->payload();
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->payload());
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

    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        return [
            'export_id' => $this->export->id,
            'status' => $this->export->status,
            'title' => __('mail.data_export.title'),
            'thread_title' => __('mail.data_export.title'),
            'excerpt' => __('mail.data_export.excerpt'),
            'url' => route('privacy.index'),
            'download_url' => $this->downloadUrl(),
            'download_expires_at' => $this->export->downloadExpiresAt()?->toIso8601String(),
            'created_at' => optional($this->export->completed_at ?? $this->export->updated_at ?? now())->toIso8601String(),
        ];
    }
}
