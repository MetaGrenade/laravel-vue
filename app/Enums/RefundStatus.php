<?php

namespace App\Enums;

/**
 * Where a refund stands. Only a succeeded refund counts as money returned.
 */
enum RefundStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Canceled = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Succeeded => 'Refunded',
            self::Failed => 'Failed',
            self::Canceled => 'Canceled',
        };
    }

    /**
     * A refund that still holds back part of the refundable balance: money
     * that is, or may yet be, on its way out.
     */
    public function reservesBalance(): bool
    {
        return $this === self::Pending || $this === self::Succeeded;
    }
}
