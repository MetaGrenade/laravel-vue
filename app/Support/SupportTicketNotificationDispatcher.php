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
                $preferredChannels = $recipient->preferredNotificationChannelsFor('support', ['database', 'mail', 'push']);
                $channels = $preferredChannels;

                if ($channelResolver) {
                    $resolvedChannels = $channelResolver($audience, $recipient);

                    if ($resolvedChannels !== null) {
                        $channels = array_map(
                            static fn (string $channel) => $channel === 'push' ? 'broadcast' : $channel,
                            $resolvedChannels,
                        );
                        $channels = array_values(array_unique($channels));
                        $channels = array_values(array_intersect($channels, $preferredChannels));
                    }
                }

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
