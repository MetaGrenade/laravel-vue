<?php

namespace App\Support\Commerce\Discounts;

use RuntimeException;

/**
 * A discount code cannot be used on this order. The message is for the shopper and is safe to show
 * as it is.
 */
class CouponRejected extends RuntimeException {}
