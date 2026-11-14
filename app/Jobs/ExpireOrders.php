<?php

namespace App\Jobs;

use App\Support\Commerce\PendingOrderExpirer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Cancels one chunk of unpaid orders whose payment window has run out, and gives their stock back (docs/commerce.md). Queued by
 * {@see ExpirePendingOrders}, {@see PendingOrderExpirer::CHUNK} orders at a time, so it makes a few provider calls and is never cut off
 * by the worker's timeout.
 *
 * It can safely run again, and the worker's retries do: it reads the orders again, and one that was cancelled, or paid, is not asked about.
 */
class ExpireOrders implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * @param  list<int>  $orderIds
     */
    public function __construct(public readonly array $orderIds) {}

    public function handle(PendingOrderExpirer $expirer): void
    {
        $expirer->expireOrders($this->orderIds);
    }
}
