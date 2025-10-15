<?php

namespace App\Support\Billing;

use App\Models\BillingInvoice;
use App\Models\BillingWebhookCall;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Cashier\Subscription;
use RuntimeException;

class BillingWebhookProcessor
{
    public function handleInvoicePaymentSucceeded(array $payload, ?int $fallbackUserId = null): void
    {
        $invoice = $this->storeInvoice($payload, 'paid');

        $this->storeWebhook($payload, $invoice?->user_id ?? $fallbackUserId);
    }

    public function handleInvoicePaymentFailed(array $payload, ?int $fallbackUserId = null): void
    {
        $invoice = $this->storeInvoice($payload, 'failed');

        $this->storeWebhook($payload, $invoice?->user_id ?? $fallbackUserId);
    }

    public function handleCustomerSubscriptionDeleted(array $payload, ?int $fallbackUserId = null): void
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

        $this->storeWebhook($payload, $subscription?->user_id ?? $fallbackUserId);
    }

    public function replay(BillingWebhookCall $call): void
    {
        $payload = $call->payload;

        if (! is_array($payload)) {
            throw new RuntimeException('Webhook payload is missing or malformed.');
        }

        $this->process($payload, $call->user_id ?: null);
    }

    public function process(array $payload, ?int $fallbackUserId = null): void
    {
        $type = (string) Arr::get($payload, 'type', '');
        $handler = $this->resolveHandler($type);

        if ($handler === null) {
            $this->storeWebhook($payload, $fallbackUserId);

            return;
        }

        $this->{$handler}($payload, $fallbackUserId);
    }

    private function resolveHandler(string $type): ?string
    {
        if ($type === '') {
            return null;
        }

        $method = 'handle'.Str::studly(str_replace('.', '_', $type));

        return method_exists($this, $method) ? $method : null;
    }

    private function storeInvoice(array $payload, ?string $statusOverride = null): ?BillingInvoice
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

    private function storeWebhook(array $payload, ?int $userId = null): void
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
}
