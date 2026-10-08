<?php

namespace App\Enums;

enum OrderPaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Paid = 'paid';
    case Failed = 'failed';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Unpaid',
            self::Paid => 'Paid',
            self::Failed => 'Payment failed',
            self::PartiallyRefunded => 'Partially refunded',
            self::Refunded => 'Refunded',
        };
    }

    /**
     * Whether the customer's money was received, including money that has since
     * been returned. A refunded order was still paid for once, which is why the
     * checks that stop a second payment or a cancellation look at this and not
     * at {@see self::Paid} alone.
     */
    public function hasReceivedPayment(): bool
    {
        return match ($this) {
            self::Paid, self::PartiallyRefunded, self::Refunded => true,
            self::Unpaid, self::Failed => false,
        };
    }
}
