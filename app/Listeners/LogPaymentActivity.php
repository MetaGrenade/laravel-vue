<?php

namespace App\Listeners;

use App\Events\Payments\PaymentProcessed;
use App\Support\Audit\AuditLogger;

class LogPaymentActivity
{
    public function handle(PaymentProcessed $event): void
    {
        AuditLogger::log(
            sprintf('billing.payment.%s', $event->action),
            'Payment activity recorded',
            [
                'payment_id' => $event->paymentId,
                'provider' => $event->provider,
                'status' => $event->status,
                'amount' => $event->amount,
                'currency' => strtoupper($event->currency),
                'metadata' => $event->metadata,
                'ip' => $event->ipAddress,
                'user_agent' => $event->userAgent,
            ],
            $event->user,
        );
    }
}

