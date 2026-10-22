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
 * Tells a subscriber that their free trial ends soon, what the plan costs after it, and that they can cancel before then.
 */
class SubscriptionTrialEnding extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  int|null  $price  What the plan costs each period, in the currency's minor units (null when the plan is not known).
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

        if ($this->price !== null && $this->currency !== null) {
            $message->line('After that your plan costs '.Money::ofMinor($this->price, $this->currency)->toDecimal().' '.$this->currency.($this->interval ? " per {$this->interval}" : '').', taken from the card you added.');
        } else {
            $message->line('After that your subscription continues and the card you added is charged.');
        }

        return $message
            ->line("If you don't want to continue, cancel before then and you won't be charged.")
            ->action('Manage your subscription', route('settings.billing.index'));
    }
}
