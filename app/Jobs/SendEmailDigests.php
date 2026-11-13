<?php

namespace App\Jobs;

use App\Support\Notifications\EmailDigest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Starts the morning email of community updates that were held for a daily digest (docs/email-digests.md).
 *
 * This job sends nothing itself. It walks the members who have something waiting, lowest id first, and queues a {@see DeliverEmailDigests}
 * job for each {@see self::CHUNK} of them. It used to email every member inside this one job, at 3.7 to 9 ms a member: the worker's
 * 60 seconds were reached at about 6,600 to 16,000 members, and the job was cut off with the lowest ids served and the highest never,
 * again every morning (with 10,000 members and 20 seconds to spare, 4,482 of them were never served in three mornings). Many short
 * jobs are never cut off, several workers can share them, and a member who cannot be served costs only their own turn.
 *
 * If this job runs twice, or stops halfway and is tried again, nobody gets a second email: a member's lines are claimed and deleted in
 * the transaction that queues their email, so a second job finds nothing left for them.
 */
class SendEmailDigests implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** How many members one {@see DeliverEmailDigests} job serves. */
    public const CHUNK = 100;

    public function handle(EmailDigest $digest): void
    {
        $after = 0;

        while (($ids = $digest->waitingMemberIds($after, self::CHUNK)) !== []) {
            DeliverEmailDigests::dispatch($ids);

            $after = max($ids);
        }
    }
}
