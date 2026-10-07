<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;
use App\Support\Ownership;

/**
 * Who may see an order. Access follows the order's owner (through
 * {@see Ownership}), never its `user_id`, so an order owned by a
 * team in 1.1 needs no change here. A guest order has no owner and is reached
 * only through its signed link.
 */
class OrderPolicy
{
    public function view(?User $user, Order $order): bool
    {
        return $user !== null && $order->isOwnedByAnyOwnerOf($user);
    }
}
