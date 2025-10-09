<?php

namespace App\Notifications;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class SupportTicketAgentReply extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, string>  $channels
     */
    public function __construct(
        protected SupportTicket $ticket,
        protected SupportTicketMessage $message,
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
        $subject = $this->ticket->subject ?? 'Support Ticket Update';

        return (new MailMessage())
            ->subject('Support ticket reply: ' . $subject)
            ->greeting('Hello ' . ($notifiable->nickname ?? $notifiable->name ?? 'there'))
            ->line('An agent has responded to your support ticket.')
            ->line('Subject: ' . $subject)
            ->line('Reply preview: ' . Str::limit((string) $this->message->body, 120))
            ->action('View ticket', route('support.tickets.show', $this->ticket))
            ->line('Thank you for your patience.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'ticket_id' => $this->ticket->id,
            'ticket_subject' => $this->ticket->subject,
            'message_id' => $this->message->id,
            'message_preview' => Str::limit((string) $this->message->body, 120),
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
}
