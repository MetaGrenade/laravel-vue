<?php

namespace App\Notifications;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use App\Notifications\Concerns\SendsBroadcastsSynchronously;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class SupportTicketAgentReply extends Notification implements ShouldQueue
{
    use Queueable;
    use SendsBroadcastsSynchronously;

    /**
     * @param  array<int, string>  $channels
     */
    public function __construct(
        protected SupportTicket $ticket,
        protected SupportTicketMessage $message,
        protected array $channels = ['mail', 'database', 'push'],
    ) {
        $this->message->setRelation('ticket', $this->ticket);
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
        $subject = $this->ticket->subject ?? 'Support Ticket Update';

        return (new MailMessage())
            ->subject('Support ticket reply: ' . $subject)
            ->greeting('Hello ' . ($notifiable->nickname ?? $notifiable->name ?? 'there'))
            ->line('An agent has responded to your support ticket.')
            ->line('Subject: ' . $subject)
            ->line('Reply preview: ' . Str::limit((string) $this->message->body, 120))
            ->action('View ticket', $this->conversationUrlFor($notifiable))
            ->line('Thank you for your patience.');
    }

    public function toArray(object $notifiable): array
    {
        return $this->payload($notifiable);
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->payload($notifiable));
    }

    public function broadcastOn($notifiable): array
    {
        $channelNames = [];

        if (method_exists($notifiable, 'getKey')) {
            $key = $notifiable->getKey();

            if ($key !== null) {
                $channelNames[] = 'App.Models.User.' . $key;
            }
        }

        $channelNames = array_values(array_unique($channelNames));

        return array_map(static fn (string $name) => new PrivateChannel($name), $channelNames);
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
    protected function payload(object $notifiable): array
    {
        $title = 'Support ticket reply: ' . $this->ticket->subject;
        $excerpt = Str::limit((string) $this->message->body, 120);

        return [
            'ticket_id' => $this->ticket->id,
            'ticket_subject' => $this->ticket->subject,
            'message_id' => $this->message->id,
            'message_preview' => $excerpt,
            'title' => $title,
            'thread_title' => $title,
            'excerpt' => $excerpt,
            'url' => $this->conversationUrlFor($notifiable),
            'created_at' => optional($this->message->created_at)->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function ticketBroadcastPayload(): ?array
    {
        if ($this->ticket->id === null || $this->message->id === null) {
            return null;
        }

        return [
            'event' => 'ticket.message.created',
            'message_id' => $this->message->id,
            'author_id' => $this->message->user_id,
            'is_from_support' => true,
            'excerpt' => Str::limit((string) $this->message->body, 120),
            'created_at' => optional($this->message->created_at)->toIso8601String(),
        ];
    }

    protected function conversationUrlFor(object $notifiable): string
    {
        $route = $notifiable instanceof User && $notifiable->can('support.acp.view')
            ? route('acp.support.tickets.show', ['ticket' => $this->ticket->id])
            : route('support.tickets.show', $this->ticket);

        return $route . '#message-' . $this->message->id;
    }
}
