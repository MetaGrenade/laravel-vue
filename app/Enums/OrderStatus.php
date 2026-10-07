<?php

namespace App\Enums;

/**
 * Where an order is in fulfilment. Payment is tracked separately by
 * {@see OrderPaymentStatus} so a paid order can still be waiting to ship.
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting payment',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * An order that has not been paid or cancelled yet and still holds stock.
     */
    public function isOpen(): bool
    {
        return $this === self::Pending;
    }
}
