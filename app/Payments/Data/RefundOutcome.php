<?php

namespace App\Payments\Data;

use App\Enums\RefundStatus;

/**
 * The provider's definite answer to a request to refund. When the provider
 * could not be reached, or its answer is unknown, it throws instead: a refund
 * must never be recorded as failed when it may have gone through.
 */
final readonly class RefundOutcome
{
    private function __construct(
        public RefundStatus $status,
        public ?string $reference,
        public ?string $failureReason,
    ) {}

    public static function of(RefundStatus $status, ?string $reference, ?string $failureReason = null): self
    {
        return new self($status, $reference, $failureReason);
    }

    /**
     * The provider refused the request (for example the charge was already fully
     * refunded), so no money moved.
     */
    public static function refused(string $reason): self
    {
        return new self(RefundStatus::Failed, null, $reason);
    }
}
