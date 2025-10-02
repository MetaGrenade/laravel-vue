<?php

namespace App\Notifications;

use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class TicketStatusUpdated extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, string>  $channels
     */
    public function __construct(
        protected SupportTicket $ticket,
        protected string $previousStatus,
        protected string $audience = 'owner',
        protected array $channels = ['mail', 'database'],
    ) {
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
        $subject = match ($this->audience) {
            'agent' => 'Ticket status updated: ' . $this->ticket->subject,
            default => 'Support ticket status updated: ' . $this->ticket->subject,
        };

        $greeting = 'Hi ' . ($notifiable->nickname ?? $notifiable->name ?? 'there') . '!';

        $lines = match ($this->audience) {
            'agent' => [
                'A support ticket you are assigned to has a new status.',
                'Subject: ' . $this->ticket->subject,
            ],
            default => [
                'Your support ticket status has been updated.',
                'Subject: ' . $this->ticket->subject,
            ],
        };

        $statusLine = sprintf(
            'Status changed from %s to %s.',
            $this->formatStatus($this->previousStatus),
            $this->formatStatus($this->ticket->status)
        );

        $mailMessage = (new MailMessage())
            ->subject($subject)
            ->greeting($greeting);

        foreach ($lines as $line) {
            $mailMessage->line($line);
        }

        $mailMessage
            ->line($statusLine)
            ->action('View conversation', $this->conversationUrlFor($notifiable))
            ->line('Thank you for using our support center.');

        return $mailMessage;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'ticket_id' => $this->ticket->id,
            'ticket_subject' => $this->ticket->subject,
            'audience' => $this->audience,
            'previous_status' => $this->previousStatus,
            'status' => $this->ticket->status,
            'url' => $this->conversationUrlFor($notifiable),
        ];
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

    protected function conversationUrlFor(object $notifiable): string
    {
        $route = $notifiable instanceof User && $notifiable->can('support.acp.view')
            ? route('acp.support.tickets.show', ['ticket' => $this->ticket->id])
            : route('support.tickets.show', $this->ticket);

        return $route;
    }

    protected function formatStatus(string $status): string
    {
        return Str::title(str_replace('_', ' ', $status));
    }
}
