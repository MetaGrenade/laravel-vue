<?php

namespace App\Events;

use App\Models\SupportTicket;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SupportTicketAssignmentUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public SupportTicket $ticket)
    {
        $this->ticket->loadMissing([
            'assignee:id,nickname,avatar_url',
            'team:id,name',
        ]);
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('support.tickets.' . $this->ticket->id)];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'ticket_id' => $this->ticket->id,
            'assigned_to' => $this->ticket->assigned_to,
            'assignee' => $this->assigneePayload(),
            'support_team_id' => $this->ticket->support_team_id,
            'team' => $this->teamPayload(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function assigneePayload(): ?array
    {
        $assignee = $this->ticket->assignee;

        if (! $assignee) {
            return null;
        }

        return [
            'id' => $assignee->id,
            'nickname' => $assignee->nickname,
            'avatar_url' => $assignee->avatar_url,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function teamPayload(): ?array
    {
        $team = $this->ticket->team;

        if (! $team) {
            return null;
        }

        return [
            'id' => $team->id,
            'name' => $team->name,
        ];
    }
}

