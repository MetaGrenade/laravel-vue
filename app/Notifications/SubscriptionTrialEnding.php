<?php

namespace App\Notifications;

use App\Support\Commerce\Money;
use App\Support\Localization\DateFormatter;
use App\Support\Mail\MailText;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a subscriber that their free trial ends soon, the plan's regular price after it, and that they can cancel before then.
 */
class SubscriptionTrialEnding extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  int|null  $price  The plan's regular price each period, in the currency's minor units (null when the plan is not known).
     */
    public function __construct(
        public readonly ?string $planName,
        public readonly CarbonInterface $endsAt,
        public readonly ?int $price,
        public readonly ?string $currency,
        public readonly ?string $interval,
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
        $date = DateFormatter::for($notifiable)->date($this->endsAt);

        $message = (new MailMessage)
            ->subject(__('mail.subscription.trial_ending.subject'))
            ->greeting(MailText::greeting($notifiable))
            ->line($this->planName ? __('mail.subscription.trial_ending.ends_plan', ['plan' => $this->planName, 'date' => $date]) : __('mail.subscription.trial_ending.ends', ['date' => $date]));

        // The plan's price, never "you will be charged X": a discount on the subscription (or tax) changes what the invoice comes to.
        if ($this->price !== null && $this->currency !== null) {
            $amount = Money::ofMinor($this->price, $this->currency)->toDecimal().' '.$this->currency;

            $message->line(__('mail.subscription.trial_ending.continues_at_price', [
                'price' => $this->interval
                    ? __('mail.subscription.trial_ending.price_per_interval', ['amount' => $amount, 'interval' => MailText::label('mail.interval', $this->interval)])
                    : $amount,
            ]));
        } else {
            $message->line(__('mail.subscription.trial_ending.continues'));
        }

        return $message
            ->line(__('mail.subscription.trial_ending.cancel'))
            ->action(__('mail.subscription.trial_ending.action'), route('settings.billing.index'));
    }
}
