<?php

namespace App\Events;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired once per support ticket activity for Discord staff mirroring (MetaForge Discord companion).
 */
class SupportTicketDiscordMirror
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  'opened'|'reply'|'updated'|'closed'  $kind
     */
    public function __construct(
        public SupportTicket $ticket,
        public string $kind,
        public ?SupportTicketMessage $message = null,
    ) {
        $this->ticket->loadMissing(['user']);
        if ($this->message !== null) {
            $this->message->loadMissing('author');
        }
    }

    public function ticket(): SupportTicket
    {
        return $this->ticket;
    }

    public function message(): ?SupportTicketMessage
    {
        return $this->message;
    }

    public function ticketUrl(): string
    {
        return route('acp.support.tickets.show', $this->ticket);
    }
}
