<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Payment for an order was confirmed. Dispatched once per order, after the
 * transaction that recorded it has committed.
 */
class OrderPaid
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Order $order) {}
}
