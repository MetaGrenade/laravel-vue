<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Notifications\Notification as BaseNotification;
use Throwable;

/**
 * Tells a group of members about one thing: the people following a thread, the comments on a blog post, or a member, and the people a
 * post names with `@`. Docs: notifications.md ("Telling many people").
 *
 * The request that makes the news only picks who is to be told and queues this. Telling a member costs two queries (their notification
 * settings, the notice in the site) and two queued sends (the email, the browser alert), so a loop in the request took 4,000 queries
 * and 4,000 queued sends, 1.6 seconds and 26 MB at 2,000 subscribers, and would not have finished at ten times that.
 *
 * Each job takes {@see self::CHUNK} members, so a big group becomes many short jobs that several workers can share and that never run
 * long enough to be cut off. With the `sync` queue it all runs at once, as it did before.
 */
class NotifyMembers implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /** How many members one job tells. */
    public const CHUNK = 100;

    /**
     * Every channel a member may have chosen; their own choices narrow it (User::notifyThroughPreferences()). The notification itself is
     * sent as each channel's own part (SplitDelivery).
     */
    private const CHANNELS = ['database', 'mail', 'push'];

    /**
     * Once. A second try of a job that stopped halfway would tell the members it had already reached a second time, and a notification
     * missing for somebody is better than one twice.
     */
    public int $tries = 1;

    /**
     * @param  list<int>  $recipientIds
     */
    public function __construct(
        public readonly BaseNotification $notification,
        public readonly array $recipientIds,
        public readonly string $category,
    ) {}

    /**
     * Queue the telling of these members, in jobs of {@see self::CHUNK}, once the request's transaction (if any) has been committed:
     * a worker that picked the job up first would look for a post that is not there yet.
     *
     * @param  iterable<int|string>  $recipientIds  Duplicates are told once.
     */
    public static function dispatchInChunks(iterable $recipientIds, BaseNotification $notification, string $category): void
    {
        $ids = [];

        foreach ($recipientIds as $id) {
            $ids[(int) $id] = (int) $id;
        }

        foreach (array_chunk(array_values($ids), self::CHUNK) as $chunk) {
            self::dispatch($notification, $chunk, $category)->afterCommit();
        }
    }

    public function handle(): void
    {
        // One query for all of their notification settings, where each member's own would have been one query each.
        $recipients = User::query()
            ->with('notificationSettings')
            ->whereKey($this->recipientIds)
            ->orderBy('id')
            ->get();

        foreach ($recipients as $recipient) {
            try {
                $recipient->notifyThroughPreferences($this->notification, $this->category, self::CHANNELS);
            } catch (Throwable $exception) {
                // One member's mailbox (or one bad row) must not leave the other ninety-nine untold.
                report($exception);
            }
        }
    }
}
