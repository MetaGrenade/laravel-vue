<?php

namespace App\Support\Commerce;

class InsufficientStockException extends CheckoutException
{
    /**
     * @param  list<string>  $items  Names of the lines that could not be reserved.
     */
    public function __construct(public readonly array $items)
    {
        parent::__construct(
            count($items) === 1
                ? "Sorry, {$items[0]} is no longer available in the quantity you chose."
                : 'Sorry, some items are no longer available in the quantity you chose: '.implode(', ', $items).'.',
        );
    }
}
