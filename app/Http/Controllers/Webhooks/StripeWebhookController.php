<?php

namespace App\Http\Controllers\Webhooks;

use App\Support\Billing\BillingWebhookProcessor;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierWebhookController;

class StripeWebhookController extends CashierWebhookController
{
    public function __construct(
        private readonly BillingWebhookProcessor $processor,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $secrets = $this->resolveWebhookSecrets();

        if ($secrets === []) {
            return new Response('Webhook signing secret not configured', 500);
        }

        if (! $this->hasValidSignature($request, $secrets)) {
            return new Response('Invalid signature', 400);
        }

        return parent::handleWebhook($request);
    }

    protected function handleInvoicePaymentSucceeded(array $payload): Response
    {
        $this->processor->handleInvoicePaymentSucceeded($payload);

        return new Response('Webhook handled', 200);
    }

    protected function handleInvoicePaymentFailed(array $payload): Response
    {
        $this->processor->handleInvoicePaymentFailed($payload);

        return new Response('Webhook handled', 200);
    }

    protected function handleCustomerSubscriptionDeleted(array $payload): Response
    {
        $this->processor->handleCustomerSubscriptionDeleted($payload);

        return new Response('Webhook handled', 200);
    }

    /**
     * @return list<string>
     */
    protected function resolveWebhookSecrets(): array
    {
        $secrets = [
            ...$this->normaliseSecrets(config('cashier.webhook.secret')),
            ...$this->normaliseSecrets(config('cashier.webhook.cli_secret')),
        ];

        return array_values(array_unique($secrets));
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    protected function normaliseSecrets($value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_array($value)) {
            return array_values(array_filter(
                array_map(
                    fn ($secret) => trim((string) $secret),
                    $value
                ),
                fn ($secret) => $secret !== ''
            ));
        }

        $string = trim((string) $value);

        if ($string === '') {
            return [];
        }

        if (str_contains($string, ',')) {
            return array_values(array_filter(
                array_map('trim', explode(',', $string)),
                fn ($secret) => $secret !== ''
            ));
        }

        return [$string];
    }

    protected function hasValidSignature(Request $request, array $secrets): bool
    {
        $signatureHeader = (string) $request->header('Stripe-Signature', '');

        if ($signatureHeader === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $signatureHeader) as $part) {
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

        $payload = $request->getContent();
        $signedPayload = $timestamp.'.'.$payload;

        foreach ($secrets as $secret) {
            $expectedSignature = hash_hmac('sha256', $signedPayload, $secret);

            foreach ($signatures as $signature) {
                if (hash_equals($expectedSignature, $signature)) {
                    return true;
                }
            }
        }

        return false;
    }
}
