<?php

namespace App\Notifications;

use App\Models\BillingInvoice;
use App\Support\Commerce\Money;
use App\Support\Localization\DateFormatter;
use App\Support\Mail\MailText;
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
        /** What Stripe tried to take, in the invoice's currency's minor units. */
        public readonly int $amount,
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
        $amount = Money::ofMinor($this->amount, $this->invoice->currency)->toDecimal().' '.$this->invoice->currency;
        $first = $this->attempt <= 1;

        $message = (new MailMessage)
            ->subject($first ? __('mail.subscription.payment_failed.subject_first') : __('mail.subscription.payment_failed.subject_again'))
            ->greeting(MailText::greeting($notifiable))
            ->line($first
                ? ($plan ? __('mail.subscription.payment_failed.first_plan', ['amount' => $amount, 'plan' => $plan]) : __('mail.subscription.payment_failed.first', ['amount' => $amount]))
                : ($plan ? __('mail.subscription.payment_failed.again_plan', ['amount' => $amount, 'plan' => $plan]) : __('mail.subscription.payment_failed.again', ['amount' => $amount])));

        // Cashier ends access at the first failed payment unless the app says otherwise. Either way it ends when Stripe gives up
        // (the subscription is then unpaid or cancelled), which is when there is no next attempt.
        $message->line(Cashier::$deactivatePastDue || $this->nextAttempt === null
            ? __('mail.subscription.payment_failed.paused')
            : __('mail.subscription.payment_failed.stays_active'));

        $message->line($this->nextAttempt !== null
            ? __('mail.subscription.payment_failed.retry_on', ['date' => DateFormatter::for($notifiable)->date($this->nextAttempt)])
            : __('mail.subscription.payment_failed.no_retry'));

        return $message
            ->line(__('mail.subscription.payment_failed.update'))
            ->action(__('mail.subscription.view_billing'), route('settings.billing.index'));
    }
}
