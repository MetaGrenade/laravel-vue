<?php

namespace App\Payments\Data;

use App\Enums\RefundStatus;
use App\Support\Commerce\Money;
use App\Support\Commerce\OrderRefunder;

/**
 * A refund as the provider reports it, in terms the shop understands. Providers
 * translate their own objects into this so {@see OrderRefunder}
 * can bring the shop's records in line without knowing which provider it is.
 */
final readonly class ProviderRefund
{
    public function __construct(
        public string $reference,
        public Money $amount,
        public RefundStatus $status,
        public ?string $reason = null,
        public ?string $failureReason = null,
        /** The id of the shop's own refund record, when the shop asked for this refund. */
        public ?int $refundId = null,
    ) {}
}
