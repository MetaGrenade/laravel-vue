<?php

namespace App\Support\Commerce;

use RuntimeException;

/**
 * A refund cannot be made. The message is written for the staff member who asked,
 * and `field` names the form field it belongs to.
 */
class RefundException extends RuntimeException
{
    public function __construct(string $message, public readonly string $field = 'amount')
    {
        parent::__construct($message);
    }
}
