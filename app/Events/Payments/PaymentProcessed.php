<?php

namespace App\Events\Payments;

use App\Models\User;
class PaymentProcessed
{
    public function __construct(
        public readonly User $user,
        public readonly string $paymentId,
        public readonly string $provider,
        public readonly string $status,
        public readonly float $amount,
        public readonly string $currency,
        public readonly string $action = 'processed',
        public readonly array $metadata = [],
        public readonly ?string $ipAddress = null,
        public readonly ?string $userAgent = null,
    ) {
    }
}

