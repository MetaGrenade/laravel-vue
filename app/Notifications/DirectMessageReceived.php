<?php

namespace App\Notifications;

use App\Models\DirectMessage;
use App\Models\User;
use App\Notifications\Concerns\SendsBroadcastsSynchronously;
use App\Support\Mail\MailText;
use App\Support\Notifications\RecordsForDigest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class DirectMessageReceived extends Notification implements RecordsForDigest, ShouldQueue
{
    use Queueable;
    use SendsBroadcastsSynchronously;

    /**
     * @param  array<int, string>  $channels
     */
    public function __construct(
        protected User $author,
        protected DirectMessage $message,
        protected array $channels = ['mail', 'database', 'push'],
    ) {}

    public function via(object $notifiable): array
    {
        return array_map(
            static fn (string $channel) => $channel === 'push' ? 'broadcast' : $channel,
            $this->channels,
        );
    }

    public function viaQueues(): array
    {
        return [
            'mail' => 'mail',
            'database' => 'default',
            'broadcast' => 'default',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = (string) $this->author->nickname;

        return (new MailMessage)
            ->subject(__('mail.direct_message.title', ['name' => $name]))
            ->greeting(MailText::greeting($notifiable))
            ->line(__('mail.direct_message.title', ['name' => $name]))
            ->line($this->excerpt())
            ->action(__('mail.direct_message.action'), $this->url())
            ->line(__('mail.direct_message.reason', ['name' => $name]));
    }

    public function toArray(object $notifiable): array
    {
        return $this->payload();
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->payload());
    }

    /**
     * @param  array<int, string>  $channels
     */
    public function withChannels(array $channels): self
    {
        $clone = clone $this;
        $clone->channels = $channels;

        return $clone;
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        $name = (string) $this->author->nickname;

        return [
            'message_id' => $this->message->id,
            'author' => $name,
            'excerpt' => $this->excerpt(),
            'title' => __('mail.direct_message.title', ['name' => $name]),
            'url' => $this->url(),
            'created_at' => optional($this->message->created_at)->toIso8601String(),
        ];
    }

    private function url(): string
    {
        return route('messages.show', $this->author);
    }

    private function excerpt(): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) $this->message->body)) ?? '');

        return Str::limit($text, 200);
    }
}
