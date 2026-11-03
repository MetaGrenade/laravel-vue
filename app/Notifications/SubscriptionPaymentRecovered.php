<?php

namespace App\Notifications;

use App\Models\BillingInvoice;
use App\Support\Commerce\Money;
use App\Support\Mail\MailText;
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
            ->subject(__('mail.subscription.payment_recovered.subject'))
            ->greeting(MailText::greeting($notifiable))
            ->line($plan ? __('mail.subscription.payment_recovered.received_plan', ['amount' => $amount, 'plan' => $plan]) : __('mail.subscription.payment_recovered.received', ['amount' => $amount]))
            ->line(__('mail.subscription.payment_recovered.active_again'))
            ->action(__('mail.subscription.view_billing'), route('settings.billing.index'));
    }
}
