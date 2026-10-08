<?php

namespace App\Support\Commerce;

/**
 * What staff asked for when they refunded an order, beyond the amount.
 */
final readonly class RefundRequest
{
    public function __construct(
        /** Identifies this submission, so a double click cannot refund twice. */
        public string $token,
        public ?string $reason = null,
        public ?string $note = null,
        /** Put the order's stock back. Only allowed when this refund completes the order. */
        public bool $restock = false,
        public bool $notifyCustomer = true,
        /** The money was returned outside the shop (cash, a bank transfer): only record it. */
        public bool $manual = false,
    ) {}
}
