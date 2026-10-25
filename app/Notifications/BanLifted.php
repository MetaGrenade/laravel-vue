<?php

namespace App\Notifications;

use App\Support\Mail\MailText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a member that they can sign in again: a moderator lifted their ban, or a suspension ran out (docs/moderation.md).
 */
class BanLifted extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  bool  $ranOut  Whether the ban ended by itself, rather than a moderator lifting it.
     * @param  string|null  $reason  What the moderator said, if they said anything.
     */
    public function __construct(
        public readonly bool $ranOut,
        public readonly ?string $reason = null,
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
            ->subject($this->ranOut ? 'Your suspension has ended' : 'Your ban has been lifted')
            ->greeting('Hi '.($notifiable->nickname ?? $notifiable->name ?? 'there').'!')
            ->line($this->ranOut
                ? 'The suspension on your account has ended. You can sign in again.'
                : 'A moderator has lifted the ban on your account. You can sign in again.');

        if (filled($this->reason)) {
            $message->line(MailText::quoted((string) $this->reason));
        }

        return $message
            ->line('Please keep to the rules: a further breach may lead to another ban.')
            ->action('Sign in', route('login'));
    }
}
