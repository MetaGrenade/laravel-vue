<?php

namespace App\Notifications;

use App\Support\Localization\DateFormatter;
use App\Support\Mail\MailText;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a member that they have been banned, why, and until when (docs/moderation.md). An account notice: sent whatever the member's
 * notification settings say.
 */
class AccountBanned extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  CarbonInterface|null  $until  When the ban ends by itself; null when it lasts until a moderator lifts it.
     */
    public function __construct(
        public readonly string $reason,
        public readonly ?CarbonInterface $until = null,
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
            ->subject($this->until !== null ? 'Your account has been suspended' : 'Your account has been banned')
            ->greeting('Hi '.($notifiable->nickname ?? $notifiable->name ?? 'there').'!')
            ->line($this->until !== null
                ? 'A moderator has suspended your account until '.DateFormatter::for($notifiable)->dayDateTime($this->until).'. You cannot sign in until then.'
                : 'A moderator has banned your account. You cannot sign in.')
            ->line('The reason given:')
            ->line(MailText::quoted($this->reason));

        if (filled(config('moderation.contact'))) {
            $message->line('If you think this was a mistake, contact '.config('moderation.contact').'.');
        }

        return $message;
    }
}
