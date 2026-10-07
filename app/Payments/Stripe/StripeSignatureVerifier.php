<?php

namespace App\Payments\Stripe;

use Illuminate\Http\Request;

/**
 * Checks the `Stripe-Signature` header of a webhook delivery against the
 * configured signing secrets (live and CLI secrets can both be set).
 */
class StripeSignatureVerifier
{
    /**
     * @return list<string>
     */
    public function secrets(): array
    {
        return array_values(array_unique([
            ...$this->normalise(config('cashier.webhook.secret')),
            ...$this->normalise(config('cashier.webhook.cli_secret')),
        ]));
    }

    public function verify(Request $request): bool
    {
        $secrets = $this->secrets();
        $header = (string) $request->header('Stripe-Signature', '');

        if ($secrets === [] || $header === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);

            if ($key === 't') {
                $timestamp = is_numeric($value) ? (int) $value : null;
            }

            if ($key !== null && str_starts_with($key, 'v') && $value !== null) {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === []) {
            return false;
        }

        $tolerance = (int) config('cashier.webhook.tolerance', 300);

        if ($tolerance > 0 && abs(now()->timestamp - $timestamp) > $tolerance) {
            return false;
        }

        $signedPayload = $timestamp.'.'.$request->getContent();

        foreach ($secrets as $secret) {
            $expected = hash_hmac('sha256', $signedPayload, $secret);

            foreach ($signatures as $signature) {
                if (hash_equals($expected, $signature)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function normalise(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $items = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(
            array_map(fn ($secret) => trim((string) $secret), $items),
            fn (string $secret) => $secret !== '',
        ));
    }
}
