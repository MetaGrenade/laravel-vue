<?php

namespace App\Notifications;

use App\Support\Mail\MailText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DailyDigest extends Notification implements ShouldQueue
{
    use Queueable;

    public const SHOWN = 20;

    /**
     * @param  list<array{category: string, title: string, excerpt: string|null, url: string}>  $items
     */
    public function __construct(protected array $items) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function viaQueues(): array
    {
        return [
            'mail' => 'mail',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $count = count($this->items);
        $message = (new MailMessage)
            ->subject('Your daily update')
            ->greeting('Hi '.($notifiable->nickname ?? 'there').'!')
            ->line($count === 1 ? 'Here is the update that was waiting for you.' : 'Here are the '.$count.' updates that were waiting for you.');

        foreach ($this->grouped() as $category => $items) {
            $label = config('notification-preferences.categories.'.$category.'.label');
            $message->line(is_string($label) && $label !== '' ? $label : $category);

            $earlier = max(count($items) - self::SHOWN, 0);
            $shown = array_slice($items, $earlier);

            foreach ($shown as $item) {
                $message->line(MailText::inline($item['title']));

                if ($item['excerpt'] !== null && $item['excerpt'] !== '') {
                    $message->line(MailText::inline($item['excerpt']));
                }

                $message->line($item['url']);
            }

            if ($earlier === 1) {
                $message->line('And 1 earlier update.');
            } elseif ($earlier > 1) {
                $message->line('And '.$earlier.' earlier updates.');
            }
        }

        return $message
            ->action('Notification settings', route('settings.notifications.edit'))
            ->line('You are receiving this email because you asked for a daily digest.');
    }

    /**
     * @return array<string, list<array{category: string, title: string, excerpt: string|null, url: string}>>
     */
    private function grouped(): array
    {
        $order = array_keys((array) config('notification-preferences.categories', []));
        $groups = [];

        foreach ($this->items as $item) {
            $groups[$item['category']][] = $item;
        }

        $sorted = [];

        foreach ($order as $category) {
            if (isset($groups[$category])) {
                $sorted[$category] = $groups[$category];
                unset($groups[$category]);
            }
        }

        foreach ($groups as $category => $items) {
            $sorted[$category] = $items;
        }

        return $sorted;
    }
}
