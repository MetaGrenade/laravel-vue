<?php

namespace App\Notifications;

use App\Support\Localization\DateFormatter;
use App\Support\Mail\MailText;
use App\Support\Security\EmailChange;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the address an account had, after the member changed it: the one place a person who did not do it can learn that it
 * happened, since from then on every email, a password reset included, goes to the new address. It is sent whatever the person's
 * notification settings say, as an account security notice is, and it carries only a masked form of the new address.
 *
 * The notifiable is the member (so the language, the name and the date format are theirs). Where it goes is decided by `User::routeNotificationForMail()`,
 * which sends this one to `$oldEmail`: a MailMessage has no recipient of its own.
 */
class EmailAddressChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $oldEmail,
        public readonly string $newEmail,
        public readonly CarbonInterface $changedAt,
        public readonly ?string $ip,
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
        $dates = DateFormatter::for($notifiable);

        $message = (new MailMessage)
            ->subject(__('mail.email_changed.subject'))
            ->greeting(MailText::greeting($notifiable))
            ->line(__('mail.email_changed.changed', [
                'new' => ApiKeyCreated::literal(EmailChange::mask($this->newEmail)),
                'date' => $dates->dayDateTime($this->changedAt),
            ]));

        if ($this->ip !== null) {
            $message->line(__('mail.email_changed.address', ['address' => ApiKeyCreated::literal($this->ip)]));
        }

        return $message
            ->line(__('mail.email_changed.what_follows'))
            ->line(__('mail.email_changed.if_not_you'))
            ->action(__('mail.email_changed.action'), route('password.edit'));
    }
}
