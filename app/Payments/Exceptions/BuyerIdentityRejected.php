<?php

namespace App\Payments\Exceptions;

/**
 * The provider would not take who the buyer says they are (a username it does not recognise). Unlike other provider
 * failures the message is for the buyer, and it is fixed on the checkout form.
 */
class BuyerIdentityRejected extends PaymentException
{
    public function __construct(string $message, public readonly string $field = 'game_username')
    {
        parent::__construct($message);
    }
}
