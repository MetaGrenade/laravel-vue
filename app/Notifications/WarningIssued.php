<?php

namespace App\Notifications;

use App\Support\Mail\MailText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a member that a moderator has warned them, and why (docs/moderation.md). It is an account notice: it is sent whatever the
 * member's notification settings say, as a security notice is.
 */
class WarningIssued extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string|null  $about  What it was about (a post, a thread), when a moderator started from one.
     */
    public function __construct(
        public readonly string $reason,
        public readonly ?string $about = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function viaQueues(): array
    {
        return ['mail' => 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject(__('mail.warning_issued.subject'))
            ->greeting(MailText::greeting($notifiable))
            ->line($this->about !== null ? __('mail.warning_issued.about', ['about' => MailText::inline($this->about)]) : __('mail.warning_issued.without_about'))
            ->line(MailText::quoted($this->reason))
            ->line(__('mail.warning_issued.nothing_else'));

        if (filled(config('moderation.contact'))) {
            $message->line(__('mail.moderation_contact', ['contact' => config('moderation.contact')]));
        }

        return $message->action(__('mail.warning_issued.action'), route('settings.standing'));
    }
}
