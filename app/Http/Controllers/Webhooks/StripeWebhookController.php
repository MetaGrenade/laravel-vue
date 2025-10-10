<?php

namespace App\Http\Controllers\Webhooks;

use App\Models\BillingInvoice;
use App\Models\BillingWebhookCall;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierWebhookController;
use Laravel\Cashier\Subscription;

class StripeWebhookController extends CashierWebhookController
{
    protected function handleInvoicePaymentSucceeded(array $payload): Response
    {
        $invoice = $this->storeInvoice($payload, 'paid');
        $this->storeWebhook($payload, $invoice?->user_id);

        return new Response('Webhook handled', 200);
    }

    protected function handleInvoicePaymentFailed(array $payload): Response
    {
        $invoice = $this->storeInvoice($payload, 'failed');
        $this->storeWebhook($payload, $invoice?->user_id);

        return new Response('Webhook handled', 200);
    }

    protected function handleCustomerSubscriptionDeleted(array $payload): Response
    {
        $subscriptionId = Arr::get($payload, 'data.object.id');
        $subscription = $subscriptionId ? Subscription::where('stripe_id', $subscriptionId)->first() : null;

        if ($subscription) {
            $subscription->stripe_status = 'canceled';
            $subscription->cancel(now());
        }

        $this->storeWebhook($payload, $subscription?->owner_id);

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
}
