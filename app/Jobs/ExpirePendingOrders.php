<?php

namespace App\Jobs;

use App\Support\Commerce\PendingOrderExpirer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Every five minutes: queues {@see ExpireOrders} for the orders whose payment window has run out, {@see PendingOrderExpirer::CHUNK} at a
 * time, lowest id first (docs/commerce.md). It asks no payment provider itself, so it is quick whatever the backlog, and an order that
 * cannot be settled holds up one small job instead of the whole run.
 */
class ExpirePendingOrders implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(PendingOrderExpirer $expirer): void
    {
        $after = 0;

        while (($ids = $expirer->dueOrderIds($after, PendingOrderExpirer::CHUNK)) !== []) {
            $after = max($ids);

            // Not the ones the provider could not be asked about lately: they would only be reported again.
            $ready = $expirer->readyToAsk($ids);

            if ($ready !== []) {
                ExpireOrders::dispatch($ready);
            }
        }
    }
}
