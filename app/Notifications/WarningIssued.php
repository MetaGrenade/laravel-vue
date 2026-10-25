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
            ->subject('A moderator has warned you')
            ->greeting('Hi '.($notifiable->nickname ?? $notifiable->name ?? 'there').'!')
            ->line('A moderator has given you a warning about something you posted'.($this->about !== null ? ': '.MailText::inline($this->about) : '.'))
            ->line(MailText::quoted($this->reason))
            ->line('Nothing else has changed: your account is as it was. But please read the rules again, as a further breach may lead to a ban.');

        if (filled(config('moderation.contact'))) {
            $message->line('If you think this was a mistake, contact '.config('moderation.contact').'.');
        }

        return $message->action('See your warnings', route('settings.standing'));
    }
}
