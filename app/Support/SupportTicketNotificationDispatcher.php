<?php

namespace App\Support;

use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Notifications\Notification;

class SupportTicketNotificationDispatcher
{
    /**
     * @param  callable(string, array<int, string>): Notification  $notificationFactory
     */
    public function dispatch(SupportTicket $ticket, callable $notificationFactory): void
    {
        $ticket->loadMissing(['user', 'assignee']);

        collect([$ticket->user, $ticket->assignee])
            ->filter(fn (?User $user) => $user !== null)
            ->unique(fn (User $user) => $user->id)
            ->each(function (User $recipient) use ($ticket, $notificationFactory): void {
                $audience = (int) $recipient->id === (int) $ticket->user_id ? 'owner' : 'agent';
                $channels = $this->preferredNotificationChannels($recipient);

                $synchronousChannels = array_values(array_intersect($channels, ['database']));
                $queuedChannels = array_values(array_diff($channels, $synchronousChannels));

                if ($synchronousChannels !== []) {
                    $recipient->notifyNow($notificationFactory($audience, $synchronousChannels));
                }

                if ($queuedChannels !== []) {
                    $recipient->notify($notificationFactory($audience, $queuedChannels));
                }
            });
    }

    /**
     * @return array<int, string>
     */
    private function preferredNotificationChannels(User $user): array
    {
        $channels = ['database'];

        if ($user->hasVerifiedEmail()) {
            array_unshift($channels, 'mail');
        }

        return array_values(array_unique($channels));
    }
}
