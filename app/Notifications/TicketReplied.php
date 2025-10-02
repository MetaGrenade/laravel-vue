<?php

namespace App\Notifications;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class TicketReplied extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param array<int, string> $channels
     */
    public function __construct(
        protected SupportTicket $ticket,
        protected SupportTicketMessage $message,
        protected string $audience = 'owner',
        protected array $channels = ['mail', 'database'],
    ) {
        $this->message->setRelation('ticket', $this->ticket);
    }

    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    public function viaQueues(): array
    {
        return [
            'mail' => 'mail',
            'database' => 'default',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $author = $this->message->author;
        $authorName = $author?->nickname ?? $author?->email ?? 'A support member';

        $subject = match ($this->audience) {
            'agent' => 'New reply on support ticket: ' . $this->ticket->subject,
            default => 'We received your reply: ' . $this->ticket->subject,
        };

        $greeting = 'Hi ' . ($notifiable->nickname ?? $notifiable->name ?? 'there') . '!';

        $messageLines = match ($this->audience) {
            'agent' => [
                'There is a new reply on a ticket you are assigned to.',
                'From: ' . $authorName,
            ],
            default => [
                'Your reply has been added to the conversation.',
                'We will follow up as soon as possible if any further action is needed.',
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
        return [
            'ticket_id' => $this->ticket->id,
            'ticket_subject' => $this->ticket->subject,
            'message_id' => $this->message->id,
            'message_author_id' => $this->message->user_id,
            'audience' => $this->audience,
            'excerpt' => $this->messageExcerpt(),
            'url' => $this->conversationUrlFor($notifiable),
        ];
    }

    /**
     * @param array<int, string> $channels
     */
    public function withChannels(array $channels): self
    {
        $clone = clone $this;
        $clone->channels = $channels;

        return $clone;
    }

    public function forAudience(string $audience): self
    {
        $clone = clone $this;
        $clone->audience = $audience;

        return $clone;
    }

    protected function messageExcerpt(): ?string
    {
        $body = $this->message->body;

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

        return $route . '#message-' . $this->message->id;
    }
}

