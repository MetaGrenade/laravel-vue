<?php

namespace App\Notifications;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use MetaForge\Discord\Contracts\DiscordNotifiableNotification;
use MetaForge\Discord\Notifications\Channels\DiscordWebhookChannel;

class SupportTicketAgentReply extends Notification implements DiscordNotifiableNotification, ShouldQueue
{
    use Queueable;

    public function __construct(
        protected SupportTicket $ticket,
        protected SupportTicketMessage $message,
    ) {
        $this->message->setRelation('ticket', $this->ticket);
    }

    public function via(object $notifiable): array
    {
        return [DiscordWebhookChannel::class];
    }

    public function discordNotificationCategory(): string
    {
        return 'support_ticket_reply';
    }

    /**
     * @return array{content?: string, embeds?: list<array<string, mixed>>}|null
     */
    public function toDiscord(object $notifiable): ?array
    {
        $subject = $this->ticket->subject ?? 'Support ticket update';
        $preview = Str::limit(trim(strip_tags((string) $this->message->body)), 200);

        return [
            'embeds' => [[
                'title' => 'Support ticket reply',
                'description' => $preview !== '' ? $preview : 'An agent replied to your ticket.',
                'fields' => [
                    ['name' => 'Subject', 'value' => $subject, 'inline' => false],
                ],
                'url' => $this->conversationUrlFor($notifiable),
            ]],
        ];
    }

    protected function conversationUrlFor(object $notifiable): string
    {
        $route = $notifiable instanceof User && $notifiable->can('support.acp.view')
            ? route('acp.support.tickets.show', ['ticket' => $this->ticket->id])
            : route('support.tickets.show', $this->ticket);

        return $route.'#message-'.$this->message->id;
    }
}
