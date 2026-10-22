<?php

namespace App\Notifications;

use App\Models\BillingInvoice;
use App\Support\Commerce\Money;
use App\Support\Localization\DateFormatter;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Laravel\Cashier\Cashier;

/**
 * Tells a subscriber that Stripe could not take the payment for their plan, what that means for what they can use, and when
 * Stripe will try again. Sent for each failed attempt, so the wording moves from "we couldn't" to "we still can't" and, when
 * Stripe is not going to try again, to "we won't".
 */
class SubscriptionPaymentFailed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly BillingInvoice $invoice,
        public readonly int $attempt,
        public readonly ?CarbonInterface $nextAttempt,
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
        $plan = $this->invoice->plan?->name;
        $amount = Money::ofMinor($this->invoice->total, $this->invoice->currency)->toDecimal().' '.$this->invoice->currency;
        $first = $this->attempt <= 1;

        $message = (new MailMessage)
            ->subject($first ? "We couldn't take your payment" : "We still couldn't take your payment")
            ->greeting('Hi '.($notifiable->nickname ?? $notifiable->name ?? 'there').'!')
            ->line(($first ? "We couldn't take your payment of {$amount}" : "We tried again and still couldn't take your payment of {$amount}").($plan ? " for {$plan}." : '.'));

        // Cashier ends access at the first failed payment unless the app says otherwise.
        $message->line(Cashier::$deactivatePastDue
            ? 'Until it goes through, what your plan gives you is paused.'
            : 'Your plan stays active while we keep trying.');

        $message->line($this->nextAttempt !== null
            ? 'We will try again on '.DateFormatter::for($notifiable)->date($this->nextAttempt).'.'
            : "We won't try again automatically, so please sort out your payment method now.");

        return $message
            ->line('Update your payment method and pay now from your billing settings.')
            ->action('Go to billing', route('settings.billing.index'));
    }
}
