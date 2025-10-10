<?php

namespace Laravel\Cashier\Exceptions;

use Exception;

class IncompletePayment extends Exception
{
    public static function invalidPaymentMethod(): self
    {
        return new self('The provided payment method could not be processed.');
    }
}
