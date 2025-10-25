<?php

namespace App\Events;

use App\Models\SupportTicket;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SupportTicketUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public SupportTicket $ticket,
        public array $payload,
    ) {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('support.tickets.' . $this->ticket->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'SupportTicketUpdated';
    }

    public function broadcastWith(): array
    {
        return array_merge([
            'ticket_id' => $this->ticket->id,
        ], $this->payload);
    }
}
