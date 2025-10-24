<?php

namespace App\Notifications;

use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\Concerns\SendsBroadcastsSynchronously;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class TicketStatusUpdated extends Notification implements ShouldQueue
{
    use Queueable;
    use SendsBroadcastsSynchronously;

    /**
     * @param  array<int, string>  $channels
     */
    public function __construct(
        protected SupportTicket $ticket,
        protected string $previousStatus,
        protected string $audience = 'owner',
        protected array $channels = ['mail', 'database', 'push'],
    ) {
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
        $subject = $this->title();

        $greeting = 'Hi ' . ($notifiable->nickname ?? $notifiable->name ?? 'there') . '!';

        $lines = match ($this->audience) {
            'agent', 'team' => [
                'A support ticket you are assigned to has a new status.',
                'Subject: ' . $this->ticket->subject,
            ],
            default => [
                'Your support ticket status has been updated.',
                'Subject: ' . $this->ticket->subject,
            ],
        };

        $mailMessage = (new MailMessage())
            ->subject($subject)
            ->greeting($greeting);

        foreach ($lines as $line) {
            $mailMessage->line($line);
        }

        $mailMessage
            ->line($this->statusLine())
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

    public function forAudience(string $audience): self
    {
        $clone = clone $this;
        $clone->audience = $audience;

        return $clone;
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(object $notifiable): array
    {
        return [
            'ticket_id' => $this->ticket->id,
            'ticket_subject' => $this->ticket->subject,
            'audience' => $this->audience,
            'previous_status' => $this->previousStatus,
            'status' => $this->ticket->status,
            'title' => $this->title(),
            'thread_title' => $this->title(),
            'excerpt' => $this->statusLine(),
            'url' => $this->conversationUrlFor($notifiable),
            'created_at' => optional($this->ticket->updated_at ?? $this->ticket->created_at)->toIso8601String(),
        ];
    }

    protected function conversationUrlFor(object $notifiable): string
    {
        $route = $notifiable instanceof User && $notifiable->can('support.acp.view')
            ? route('acp.support.tickets.show', ['ticket' => $this->ticket->id])
            : route('support.tickets.show', $this->ticket);

        return $route;
    }

    protected function title(): string
    {
        return match ($this->audience) {
            'agent', 'team' => 'Ticket status updated: ' . $this->ticket->subject,
            default => 'Support ticket status updated: ' . $this->ticket->subject,
        };
    }

    protected function statusLine(): string
    {
        return sprintf(
            'Status changed from %s to %s.',
            $this->formatStatus($this->previousStatus),
            $this->formatStatus($this->ticket->status)
        );
    }

    protected function formatStatus(string $status): string
    {
        return Str::title(str_replace('_', ' ', $status));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function ticketBroadcastPayload(): ?array
    {
        if ($this->ticket->id === null) {
            return null;
        }

        return [
            'event' => 'ticket.status.updated',
            'status' => $this->ticket->status,
            'previous_status' => $this->previousStatus,
            'created_at' => optional($this->ticket->updated_at ?? $this->ticket->created_at)->toIso8601String(),
        ];
    }
}
