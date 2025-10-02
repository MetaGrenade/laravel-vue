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

    public function __construct(
        protected SupportTicket $ticket,
        protected SupportTicketMessage $message
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
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
}
