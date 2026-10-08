<?php

namespace App\Payments\Exceptions;

use RuntimeException;

/**
 * The provider answered a refund request with a refusal, so it is certain that
 * no money moved. Any other failure leaves the outcome unknown.
 */
class RefundRejected extends RuntimeException {}
