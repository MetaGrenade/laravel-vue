<?php

namespace App\Support\Commerce;

use RuntimeException;

/**
 * A wishlist change that cannot be made; the message is fit to show the customer.
 */
class WishlistException extends RuntimeException {}
