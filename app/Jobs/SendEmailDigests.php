<?php

namespace App\Jobs;

use App\Support\Notifications\EmailDigest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sends the morning email of community updates that were held for a daily digest (docs/email-digests.md).
 */
class SendEmailDigests implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(EmailDigest $digest): void
    {
        $digest->sendDue();
    }
}
