<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\Failed\PrunableFailedJobProvider;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Deletes the record of a job that failed once it is older than `queue.failed.retention_days`, so that `failed_jobs` does not grow for ever.
 * A retention of 0 or less keeps everything.
 *
 * Nothing in Laravel deletes a row of `failed_jobs`: `queue:prune-failed` exists and nobody runs it. A row holds the whole serialised job
 * and the exception with its stack trace, and a service that is down for a day (Meilisearch, the mail server, Stripe) leaves one for every
 * job that needed it.
 *
 * It uses the failed job storage Laravel is configured with and deletes what that storage deletes for `queue:prune-failed`; a storage that
 * cannot prune (`QUEUE_FAILED_DRIVER=null`, or one a site added) is left alone. The job batches table is not touched: nothing here makes a
 * batch, so there is nothing in it to delete.
 */
class PruneFailedJobs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $days = (int) config('queue.failed.retention_days', 30);

        // Whatever `queue.failed.driver` names: the database ones prune, `null` does not, and the one a site adds may not.
        /** @var FailedJobProviderInterface $failer */
        $failer = app('queue.failer');

        if ($days <= 0 || ! $failer instanceof PrunableFailedJobProvider) {
            return;
        }

        // The storage deletes in chunks of its own (1,000 at a time), so a backlog is not one statement that holds the table.
        $failer->prune(now()->subDays($days));
    }
}
