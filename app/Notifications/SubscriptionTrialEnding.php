<?php

namespace App\Notifications;

use App\Support\Commerce\Money;
use App\Support\Localization\DateFormatter;
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
            ->subject('Your free trial ends soon')
            ->greeting('Hi '.($notifiable->nickname ?? $notifiable->name ?? 'there').'!')
            ->line('Your free trial'.($this->planName ? " of {$this->planName}" : '')." ends on {$date}.");

        // The plan's price, never "you will be charged X": a discount on the subscription (or tax) changes what the invoice comes to.
        if ($this->price !== null && $this->currency !== null) {
            $message->line('After that your plan continues at its regular price of '.Money::ofMinor($this->price, $this->currency)->toDecimal().' '.$this->currency.($this->interval ? " per {$this->interval}" : '').'. Any discount on your subscription is taken off, and anything left to pay is charged to the card you added.');
        } else {
            $message->line('After that your subscription continues, and anything due is charged to the card you added.');
        }

        return $message
            ->line("If you don't want to continue, cancel before then and you won't be charged.")
            ->action('Manage your subscription', route('settings.billing.index'));
    }
}
