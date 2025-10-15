<?php

namespace App\Jobs;

use App\Jobs\Concerns\NotifiesOperationsOnFailure;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Notifications\TicketOpened;
use App\Support\SupportTicketNotificationDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class HandleSupportTicketOpened implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use NotifiesOperationsOnFailure;

    public int $tries = 3;

    public int $backoff = 30;

    public string $queue = 'notifications';

    public function __construct(
        public int $ticketId,
        public int $messageId,
        public int $actorId,
    ) {
    }

    public function handle(SupportTicketNotificationDispatcher $dispatcher): void
    {
        $ticket = SupportTicket::query()
            ->with(['user', 'assignee.supportTeams.members', 'team.members'])
            ->find($this->ticketId);

        if (! $ticket) {
            return;
        }

        $message = SupportTicketMessage::query()
            ->with(['author'])
            ->where('support_ticket_id', $ticket->id)
            ->find($this->messageId);

        $dispatcher->dispatch($ticket, function (string $audience) use ($ticket, $message) {
            return (new TicketOpened($ticket, $message))
                ->forAudience($audience);
        });

        Cache::increment('metrics:support.tickets.opened');

        Log::info('Dispatched support ticket opened notifications.', [
            'ticket_id' => $ticket->id,
            'message_id' => $message?->id,
            'actor_id' => $this->actorId,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $this->notifyOfFailure($exception, [
            'job' => static::class,
            'reference' => sprintf('ticket_id=%d,message_id=%d', $this->ticketId, $this->messageId),
            'message' => 'Failed to dispatch support ticket opened notifications.',
        ]);
    }
}
