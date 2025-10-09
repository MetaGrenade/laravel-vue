<?php

namespace App\Support;

use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Notification;

class SupportTicketNotificationDispatcher
{
    /**
     * @param  callable(string, array<int, string>): BaseNotification  $notificationFactory
     */
    public function dispatch(SupportTicket $ticket, callable $notificationFactory): void
    {
        $ticket->loadMissing(['user.notificationSettings', 'assignee.notificationSettings']);

        collect([$ticket->user, $ticket->assignee])
            ->filter(fn (?User $user) => $user !== null)
            ->unique(fn (User $user) => $user->id)
            ->each(function (User $recipient) use ($ticket, $notificationFactory): void {
                $audience = (int) $recipient->id === (int) $ticket->user_id ? 'owner' : 'agent';
                $channels = $recipient->preferredNotificationChannelsFor('support', ['mail', 'database']);

                $synchronousChannels = array_values(array_intersect($channels, ['database']));
                $queuedChannels = array_values(array_diff($channels, $synchronousChannels));

                if ($synchronousChannels !== []) {
                    Notification::sendNow($recipient, $notificationFactory($audience, $synchronousChannels));
                }

                if ($queuedChannels !== []) {
                    Notification::send($recipient, $notificationFactory($audience, $queuedChannels));
                }
            });
    }

}
