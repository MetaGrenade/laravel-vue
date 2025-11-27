<?php

namespace App\Http\Controllers\Concerns;

use Laravel\Cashier\Exceptions\IncompletePayment;
use Laravel\Cashier\Payment;

trait InteractsWithStripe
{
    protected function shouldBypassStripe(): bool
    {
        return blank((string) config('cashier.secret')) || app()->environment('testing');
    }

    protected function extractPaymentIntent(IncompletePayment $exception): ?object
    {
        $intent = $this->normalizePaymentIntent($exception->payment ?? null);

        if ($intent !== null) {
            return $intent;
        }

        if (property_exists($exception, 'paymentIntent')) {
            $intent = $this->normalizePaymentIntent($exception->paymentIntent);

            if ($intent !== null) {
                return $intent;
            }
        }

        if (method_exists($exception, 'paymentIntent')) {
            return $this->normalizePaymentIntent($exception->paymentIntent());
        }

        return null;
    }

    protected function normalizePaymentIntent($value): ?object
    {
        if ($value instanceof Payment) {
            $value = $value->asStripePaymentIntent();
        }

        if (is_array($value)) {
            $value = (object) $value;
        }

        if (is_object($value)) {
            if ($value instanceof Payment) {
                return $this->normalizePaymentIntent($value->asStripePaymentIntent());
            }

            if (isset($value->id)) {
                return $value;
            }
        }

        return null;
    }
}
