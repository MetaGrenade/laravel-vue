<?php

namespace App\Support\Billing;

use App\Events\Payments\PaymentProcessed;
use App\Models\User;

class PaymentService
{
    public function record(
        User $user,
        string $paymentId,
        float $amount,
        string $currency,
        string $provider,
        string $status = 'succeeded',
        string $action = 'processed',
        array $metadata = [],
    ): void {
        event(new PaymentProcessed(
            user: $user,
            paymentId: $paymentId,
            provider: $provider,
            status: $status,
            amount: $amount,
            currency: $currency,
            action: $action,
            metadata: $metadata,
            ipAddress: request()?->ip(),
            userAgent: request()?->userAgent(),
        ));
    }
}

