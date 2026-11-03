<?php

namespace App\Notifications;

use App\Support\Mail\MailText;
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
            ->subject(__('mail.subscription.cancelled_for_non_payment.subject'))
            ->greeting(MailText::greeting($notifiable))
            ->line($this->planName !== null ? __('mail.subscription.cancelled_for_non_payment.ended', ['plan' => $this->planName]) : __('mail.subscription.cancelled_for_non_payment.ended_unknown_plan'))
            ->line(__('mail.subscription.cancelled_for_non_payment.welcome_back'))
            ->action(__('mail.subscription.cancelled_for_non_payment.action'), route('pricing'));
    }
}
