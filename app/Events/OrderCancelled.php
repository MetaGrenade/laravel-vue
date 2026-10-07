<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An unpaid order was cancelled and its stock released. Dispatched after the
 * transaction that recorded it has committed.
 */
class OrderCancelled
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly string $reason,
    ) {}
}
