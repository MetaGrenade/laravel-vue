<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a subscriber that their subscription has ended because the payment for it could not be taken, however many times
 * Stripe tried.
 */
class SubscriptionCancelledForNonPayment extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly ?string $planName) {}

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
        return (new MailMessage)
            ->subject('Your subscription has ended')
            ->greeting('Hi '.($notifiable->nickname ?? $notifiable->name ?? 'there').'!')
            ->line("We couldn't take payment for ".($this->planName ?? 'your plan').' after several tries, so your subscription has ended and what it gave you is no longer available.')
            ->line('You are welcome back at any time: choose a plan and add a payment method that works.')
            ->action('Choose a plan', route('pricing'));
    }
}
