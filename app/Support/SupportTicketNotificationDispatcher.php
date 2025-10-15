<?php

namespace App\Support;

use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Notifications\Notification as BaseNotification;

class SupportTicketNotificationDispatcher
{
    /**
     * @param  callable(string): BaseNotification  $notificationFactory
     */
    public function dispatch(SupportTicket $ticket, callable $notificationFactory): void
    {
        $ticket->loadMissing(['user', 'assignee']);

        collect([$ticket->user, $ticket->assignee])
            ->filter(fn (?User $user) => $user !== null)
            ->unique(fn (User $user) => $user->id)
            ->each(function (User $recipient) use ($ticket, $notificationFactory): void {
                $audience = (int) $recipient->id === (int) $ticket->user_id ? 'owner' : 'agent';
                $channels = $recipient->preferredNotificationChannelsFor('support', ['database', 'mail', 'push']);

                if ($channels === []) {
                    return;
                }

                $synchronousChannels = array_values(array_intersect($channels, ['database']));
                $queuedChannels = array_values(array_diff($channels, $synchronousChannels));

                if ($synchronousChannels !== []) {
                    $recipient->notifyNow(
                        $this->notificationWithChannels(
                            $notificationFactory($audience),
                            $synchronousChannels,
                        ),
                        $synchronousChannels,
                    );
                }

                if ($queuedChannels !== []) {
                    $recipient->notify(
                        $this->notificationWithChannels(
                            $notificationFactory($audience),
                            $queuedChannels,
                        ),
                    );
                }
            });
    }

    /**
     * @param  array<int, string>  $channels
     */
    protected function notificationWithChannels(BaseNotification $notification, array $channels): BaseNotification
    {
        if (method_exists($notification, 'withChannels')) {
            return $notification->withChannels($channels);
        }

        if (property_exists($notification, 'channels')) {
            $notification->channels = $channels;
        }

        return $notification;
    }

}
