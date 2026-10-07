<?php

namespace App\Enums;

/**
 * State of one payment attempt. An order can have several attempts (the
 * customer abandons one checkout and starts another), but at most one succeeds.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Canceled = 'canceled';

    /**
     * The provider reported an amount or currency that does not match the
     * order. The money is not applied to the order until a person checks it.
     */
    case Review = 'review';

    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }
}
