<?php

namespace App\Notifications;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class TicketOpened extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param array<int, string> $channels
     */
    public function __construct(
        protected SupportTicket $ticket,
        protected ?SupportTicketMessage $message = null,
        protected string $audience = 'owner',
        protected array $channels = ['mail', 'database', 'push'],
    ) {
        if ($this->message) {
            $this->message->setRelation('ticket', $this->ticket);
        }
    }

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
        $subject = match ($this->audience) {
            'agent' => 'New support ticket: ' . $this->ticket->subject,
            default => 'Support ticket received: ' . $this->ticket->subject,
        };

        $greeting = 'Hi ' . ($notifiable->nickname ?? $notifiable->name ?? 'there') . '!';

        $messageLines = match ($this->audience) {
            'agent' => [
                'A new support ticket requires your attention.',
                'Subject: ' . $this->ticket->subject,
                'From: ' . ($this->ticket->user?->nickname ?? $this->ticket->user?->email ?? 'Customer'),
            ],
            default => [
                'Thanks for contacting our support team. Your ticket has been opened and our agents will take a look shortly.',
                'Subject: ' . $this->ticket->subject,
            ],
        };

        $mailMessage = (new MailMessage())
            ->subject($subject)
            ->greeting($greeting);

        foreach ($messageLines as $line) {
            $mailMessage->line($line);
        }

        if ($excerpt = $this->messageExcerpt()) {
            $mailMessage->line('Message: ' . $excerpt);
        }

        $mailMessage
            ->action('View conversation', $this->conversationUrlFor($notifiable))
            ->line('Thank you for using our support center.');

        return $mailMessage;
    }

    public function toArray(object $notifiable): array
    {
        return $this->payload($notifiable);
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->payload($notifiable));
    }

    /**
     * @param array<int, string> $channels
     */
    public function withChannels(array $channels): self
    {
        $this->channels = $channels;

        return $this;
    }

    public function forAudience(string $audience): self
    {
        $this->audience = $audience;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(object $notifiable): array
    {
        return [
            'ticket_id' => $this->ticket->id,
            'ticket_subject' => $this->ticket->subject,
            'message_id' => $this->message?->id,
            'audience' => $this->audience,
            'title' => $this->title(),
            'thread_title' => $this->title(),
            'excerpt' => $this->databaseExcerpt(),
            'url' => $this->conversationUrlFor($notifiable),
            'created_at' => optional($this->message?->created_at ?? $this->ticket->created_at)->toIso8601String(),
        ];
    }

    protected function title(): string
    {
        return match ($this->audience) {
            'agent' => 'New support ticket: ' . $this->ticket->subject,
            default => 'Support ticket opened: ' . $this->ticket->subject,
        };
    }

    protected function databaseExcerpt(): string
    {
        if ($excerpt = $this->messageExcerpt()) {
            return $excerpt;
        }

        return match ($this->audience) {
            'agent' => 'A new support ticket requires your attention.',
            default => 'We received your support request and will follow up soon.',
        };
    }

    protected function messageExcerpt(): ?string
    {
        $body = $this->message?->body ?? $this->ticket->body;

        if (! $body) {
            return null;
        }

        $normalized = trim(preg_replace('/\s+/', ' ', strip_tags($body)) ?? '');

        return $normalized !== '' ? Str::limit($normalized, 140) : null;
    }

    protected function conversationUrlFor(object $notifiable): string
    {
        $route = $notifiable instanceof User && $notifiable->can('support.acp.view')
            ? route('acp.support.tickets.show', ['ticket' => $this->ticket->id])
            : route('support.tickets.show', $this->ticket);

        if ($this->message) {
            return $route . '#message-' . $this->message->id;
        }

        return $route;
    }
}

