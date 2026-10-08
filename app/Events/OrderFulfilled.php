<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An order was marked fulfilled (shipped, or delivered if it is digital).
 * Dispatched after the transaction that recorded it has committed.
 */
class OrderFulfilled
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly bool $notifyCustomer = true,
    ) {}
}
