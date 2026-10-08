<?php

namespace App\Events;

use App\Models\Order;
use App\Models\Refund;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A refund succeeded for the first time. Dispatched once per refund, after the
 * transaction that recorded it has committed. The customer is only emailed when
 * the refund asks for it ({@see Refund::$notify_customer}).
 */
class OrderRefunded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly Refund $refund,
    ) {}
}
