<?php

namespace App\Payments\Exceptions;

use RuntimeException;

/**
 * A payment provider call failed or was refused. The message is for logs and
 * support, not for shoppers.
 */
class PaymentException extends RuntimeException {}
