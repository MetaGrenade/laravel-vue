<?php

namespace App\Support\Notifications;

use Closure;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Sends one notification in two parts: the notice in the site now, so it is there when the page is drawn, and everything else
 * (email, the browser alert) through the queue.
 *
 * Both parts carry the same id. Laravel gives every send an id of its own, so without this the notice in the site and the alert
 * pushed to the browser would be two notifications, and the id in the alert would belong to no stored row: marking it read or
 * dismissing it would ask the server about something that does not exist.
 */
class SplitDelivery
{
    /** The channels written during the request. */
    public const NOW = ['database'];

    /**
     * @param  array<int, string>  $channels  Every channel the recipient is to be reached on.
     * @param  Closure(array<int, string>): BaseNotification  $notificationFor  The notification, limited to the channels it is given.
     */
    public function send(object $notifiable, array $channels, Closure $notificationFor): void
    {
        $now = array_values(array_intersect($channels, self::NOW));
        $queued = array_values(array_diff($channels, $now));
        $id = (string) Str::uuid();

        if ($now !== []) {
            $notification = $notificationFor($now);
            $notification->id = $id;

            Notification::sendNow($notifiable, $notification, $now);
        }

        if ($queued !== []) {
            $notification = $notificationFor($queued);
            $notification->id = $id;

            Notification::send($notifiable, $notification);
        }
    }
}
