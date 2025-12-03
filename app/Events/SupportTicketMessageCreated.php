<?php

namespace App\Events;

use App\Models\SupportTicketMessage;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SupportTicketMessageCreated implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public SupportTicketMessage $message)
    {
        $this->message->loadMissing(['author:id,nickname,avatar_url']);
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('support.tickets.' . $this->message->support_ticket_id)];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'ticket_id' => $this->message->support_ticket_id,
            'id' => $this->message->id,
            'body' => $this->message->body,
            'author' => $this->authorPayload(),
            'created_at' => optional($this->message->created_at)->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function authorPayload(): ?array
    {
        $author = $this->message->author;

        if (! $author) {
            return null;
        }

        return [
            'id' => $author->id,
            'nickname' => $author->nickname,
            'avatar_url' => $author->avatar_url,
        ];
    }
}

