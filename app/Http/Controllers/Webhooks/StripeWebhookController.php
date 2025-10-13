<?php

namespace App\Http\Controllers\Webhooks;

use App\Models\BillingInvoice;
use App\Models\BillingWebhookCall;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierWebhookController;
use Laravel\Cashier\Subscription;
use function activity;

class StripeWebhookController extends CashierWebhookController
{
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
        $invoice = $this->storeInvoice($payload, 'paid');
        $this->storeWebhook($payload, $invoice?->user_id);

        if ($invoice) {
            $this->logInvoiceActivity(
                $invoice,
                'billing.invoice.paid',
                sprintf('Invoice %s marked as paid', $invoice->stripe_id ?? $invoice->id),
                $payload,
            );
        }

        return new Response('Webhook handled', 200);
    }

    protected function handleInvoicePaymentFailed(array $payload): Response
    {
        $invoice = $this->storeInvoice($payload, 'failed');
        $this->storeWebhook($payload, $invoice?->user_id);

        if ($invoice) {
            $this->logInvoiceActivity(
                $invoice,
                'billing.invoice.failed',
                sprintf('Invoice %s payment failed', $invoice->stripe_id ?? $invoice->id),
                $payload,
            );
        }

        return new Response('Webhook handled', 200);
    }

    protected function handleCustomerSubscriptionDeleted(array $payload): Response
    {
        $subscriptionId = Arr::get($payload, 'data.object.id');
        $subscription = $subscriptionId ? Subscription::where('stripe_id', $subscriptionId)->first() : null;

        if ($subscription) {
            $subscription->stripe_status = 'canceled';

            $secret = (string) config('cashier.secret', '');

            if ($secret !== '') {
                $subscription->cancel(now());
            } else {
                $subscription->forceFill([
                    'ends_at' => now(),
                ])->save();
            }
        }

        $this->storeWebhook($payload, $subscription?->user_id);

        if ($subscription) {
            $this->logSubscriptionCancellation($subscription, $payload);
        }

        return new Response('Webhook handled', 200);
    }

    protected function storeInvoice(array $payload, ?string $statusOverride = null): ?BillingInvoice
    {
        $invoice = Arr::get($payload, 'data.object');

        if (! is_array($invoice) || ! isset($invoice['id'])) {
            return null;
        }

        $stripeId = (string) $invoice['id'];
        $customerId = Arr::get($invoice, 'customer');

        $user = $customerId ? User::where('stripe_id', $customerId)->first() : null;

        $priceId = Arr::get($invoice, 'lines.data.0.price.id');
        $plan = $priceId ? SubscriptionPlan::where('stripe_price_id', $priceId)->first() : null;

        $status = $statusOverride ?? (string) ($invoice['status'] ?? 'open');

        $dueDate = Arr::get($invoice, 'due_date');
        $paidAt = Arr::get($invoice, 'status_transitions.paid_at');

        return BillingInvoice::updateOrCreate(
            ['stripe_id' => $stripeId],
            [
                'user_id' => $user?->id,
                'subscription_plan_id' => $plan?->id,
                'stripe_customer_id' => $customerId,
                'status' => $status,
                'currency' => strtoupper((string) ($invoice['currency'] ?? config('billing.currency', 'USD'))),
                'subtotal' => (int) ($invoice['subtotal'] ?? 0),
                'tax' => (int) ($invoice['tax'] ?? 0),
                'total' => (int) ($invoice['total'] ?? $invoice['amount_due'] ?? 0),
                'due_at' => $dueDate ? Carbon::createFromTimestamp($dueDate) : null,
                'paid_at' => $paidAt ? Carbon::createFromTimestamp($paidAt) : null,
                'data' => $invoice,
            ]
        );
    }

    protected function logInvoiceActivity(BillingInvoice $invoice, string $event, string $message, array $payload = []): void
    {
        $properties = [
            'attributes' => [
                'invoice_id' => $invoice->id,
                'stripe_id' => $invoice->stripe_id,
                'status' => $invoice->status,
                'total' => $invoice->total,
                'currency' => $invoice->currency,
                'user_id' => $invoice->user_id,
                'subscription_plan_id' => $invoice->subscription_plan_id,
            ],
        ];

        if ($payload !== []) {
            $properties['payload'] = Arr::only($payload, ['id', 'type', 'created']);
        }

        activity('billing')
            ->event($event)
            ->performedOn($invoice)
            ->causedBy($invoice->user)
            ->withProperties($properties)
            ->log($message);
    }

    protected function logSubscriptionCancellation(Subscription $subscription, array $payload = []): void
    {
        $properties = [
            'attributes' => [
                'subscription_id' => $subscription->id,
                'stripe_id' => $subscription->stripe_id,
                'status' => $subscription->stripe_status,
                'user_id' => $subscription->user_id,
            ],
        ];

        if ($payload !== []) {
            $properties['payload'] = Arr::only($payload, ['id', 'type', 'created']);
        }

        activity('billing')
            ->event('billing.subscription.canceled')
            ->performedOn($subscription)
            ->causedBy($subscription->user)
            ->withProperties($properties)
            ->log(sprintf('Subscription %s canceled', $subscription->stripe_id ?? $subscription->id));
    }

    protected function storeWebhook(array $payload, ?int $userId = null): void
    {
        if (! config('billing.webhooks.store_payloads', true)) {
            return;
        }

        BillingWebhookCall::updateOrCreate(
            ['stripe_id' => Arr::get($payload, 'id')],
            [
                'user_id' => $userId,
                'type' => (string) Arr::get($payload, 'type', 'unknown'),
                'payload' => $payload,
                'processed_at' => now(),
            ]
        );
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
