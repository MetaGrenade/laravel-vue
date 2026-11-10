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
 * Sent to a member after somebody who runs the site switched two-factor off for their account (`TwoFactorReset`): the one place a person
 * who did not ask for it can learn that it happened. It is sent whatever the person's notification settings say, as an account security
 * notice is. It does not name who did it or why: that is in the audit log, where staff read it, and a reason can be about somebody else.
 *
 * The notifiable is the member, so the language, the name and the date format are theirs.
 */
class TwoFactorWasReset extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly CarbonInterface $resetAt) {}

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
        $dates = DateFormatter::for($notifiable);

        return (new MailMessage)
            ->subject(__('mail.two_factor_reset.subject'))
            ->greeting(MailText::greeting($notifiable))
            ->line(__('mail.two_factor_reset.reset', ['date' => $dates->dayDateTime($this->resetAt)]))
            ->line(__('mail.two_factor_reset.what_follows'))
            ->line(__('mail.two_factor_reset.if_not_you'))
            ->action(__('mail.two_factor_reset.action'), route('security.edit'));
    }
}
