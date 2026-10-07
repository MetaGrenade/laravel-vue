<?php

namespace App\Support\Commerce;

use RuntimeException;

/**
 * A checkout problem the shopper can understand and fix. The message is safe
 * to show to them as is.
 */
class CheckoutException extends RuntimeException {}
