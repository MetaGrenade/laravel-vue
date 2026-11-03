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
            ->subject($this->until !== null ? __('mail.account_banned.subject_suspended') : __('mail.account_banned.subject_banned'))
            ->greeting(MailText::greeting($notifiable))
            ->line($this->until !== null
                ? __('mail.account_banned.suspended', ['date' => DateFormatter::for($notifiable)->dayDateTime($this->until)])
                : __('mail.account_banned.banned'))
            ->line(__('mail.account_banned.reason'))
            ->line(MailText::quoted($this->reason));

        if (filled(config('moderation.contact'))) {
            $message->line(__('mail.moderation_contact', ['contact' => config('moderation.contact')]));
        }

        return $message;
    }
}
