<?php

namespace App\Notifications;

use App\Models\BillingInvoice;
use App\Support\Commerce\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a subscriber that a payment that had failed has now gone through.
 */
class SubscriptionPaymentRecovered extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  int  $amount  What was paid, in the invoice's currency's minor units.
     */
    public function __construct(public readonly BillingInvoice $invoice, public readonly int $amount) {}

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
        $amount = Money::ofMinor($this->amount, $this->invoice->currency)->toDecimal().' '.$this->invoice->currency;

        return (new MailMessage)
            ->subject('Payment received, thank you')
            ->greeting('Hi '.($notifiable->nickname ?? $notifiable->name ?? 'there').'!')
            ->line("We've taken your payment of {$amount}".($plan ? " for {$plan}." : '.'))
            ->line('Your plan is active again.')
            ->action('Go to billing', route('settings.billing.index'));
    }
}
