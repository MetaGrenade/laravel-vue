<?php

namespace App\Jobs;

use App\Support\Notifications\EmailDigest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Sends the morning email to one chunk of members (docs/email-digests.md). Queued by {@see SendEmailDigests}, {@see SendEmailDigests::CHUNK}
 * members at a time, so one takes a second or two even when every member has a hundred lines waiting, and never runs long enough to be cut
 * off.
 *
 * It can safely run again, and the worker's retries do: each member's lines are claimed and deleted in the transaction that queues their
 * email ({@see EmailDigest::deliverTo()}), so a member who was served has nothing left for the second run, and one who was not is served.
 */
class DeliverEmailDigests implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * @param  list<int>  $userIds
     */
    public function __construct(public readonly array $userIds) {}

    public function handle(EmailDigest $digest): void
    {
        $digest->deliverTo($this->userIds);
    }
}
