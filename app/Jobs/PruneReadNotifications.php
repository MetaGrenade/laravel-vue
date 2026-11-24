<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Deletes a notification that has been read once that was more than `notifications.read_retention_days` ago, so that `notifications` does
 * not keep every notification a member was ever sent. A retention of 0 or less keeps everything.
 *
 * Only a read one: the bell shows the notifications that have not been read, and no page of the site lists one that has, so nothing is
 * lost that a member can see. One that has not been read is never deleted, however old; it is in the bell, and its count is on every page.
 *
 * It counts from when the notification was read, not from when it was made: a member who reads a notification from last month today
 * has not had it for 30 days, and the same job would otherwise delete it the night after.
 */
class PruneReadNotifications implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $days = (int) config('notifications.read_retention_days', 30);

        if ($days <= 0) {
            return;
        }

        $cutoff = now()->subDays($days);

        // In chunks: one huge delete would hold locks on a table that is written to whenever a member is notified or reads the bell.
        // A notification that has not been read has no `read_at`, and a missing time is not before any date, so it is never in the list.
        do {
            $deleted = DatabaseNotification::query()
                ->where('read_at', '<', $cutoff)
                ->limit(5000)
                ->delete();
        } while ($deleted > 0);
    }
}
