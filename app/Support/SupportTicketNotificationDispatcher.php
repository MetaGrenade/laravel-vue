<?php

namespace App\Support;

use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Notifications\Notification as BaseNotification;

class SupportTicketNotificationDispatcher
{
    /**
     * @param  callable(string): BaseNotification  $notificationFactory
     * @param  callable(string, User): array<int, string>|null  $channelResolver
     */
    public function dispatch(
        SupportTicket $ticket,
        callable $notificationFactory,
        ?callable $channelResolver = null,
    ): void {
        $ticket->loadMissing(['user', 'assignee']);

        collect([$ticket->user, $ticket->assignee])
            ->filter(fn (?User $user) => $user !== null)
            ->unique(fn (User $user) => $user->id)
            ->each(function (User $recipient) use ($ticket, $notificationFactory, $channelResolver): void {
                $audience = (int) $recipient->id === (int) $ticket->user_id ? 'owner' : 'agent';
                $notification = $notificationFactory($audience);

                $candidateChannels = $channelResolver
                    ? $channelResolver($audience, $recipient)
                    : ['database', 'mail', 'push'];

                $recipient->notifyThroughPreferences($notification, 'support', $candidateChannels);
            });
    }

}
