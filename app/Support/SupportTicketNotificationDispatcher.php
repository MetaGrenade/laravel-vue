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
                    Notification::sendNow($recipient, $notificationFactory($audience, $synchronousChannels));
                }

                if ($queuedChannels !== []) {
                    Notification::send($recipient, $notificationFactory($audience, $queuedChannels));
                }
            });
    }

    /**
     * @return array<int, string>
     */
    private function preferredNotificationChannels(User $user): array
    {
        $preferences = $user->notification_preferences ?? [];

        $supportTicketPreferences = array_merge(
            [
                'mail' => true,
                'push' => false,
                'database' => true,
            ],
            is_array($preferences['support_ticket'] ?? null)
                ? $preferences['support_ticket']
                : [],
        );

        $channels = [];

        if (($supportTicketPreferences['mail'] ?? false) && $user->hasVerifiedEmail()) {
            $channels[] = 'mail';
        }

        if ($supportTicketPreferences['push'] ?? false) {
            $channels[] = 'broadcast';
        }

        if ($supportTicketPreferences['database'] ?? false) {
            $channels[] = 'database';
        }

        return array_values(array_unique($channels));
    }
}
