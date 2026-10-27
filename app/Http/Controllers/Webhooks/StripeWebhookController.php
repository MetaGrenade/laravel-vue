<?php

namespace App\Http\Controllers\Webhooks;

use App\Models\BillingWebhookCall;
use App\Payments\Providers\StripeProvider;
use App\Payments\Stripe\StripeSignatureVerifier;
use App\Support\Billing\BillingWebhookProcessor;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierWebhookController;

/**
 * One endpoint for everything Stripe sends. Subscription and invoice events go
 * through Cashier and the billing processor; checkout sessions created for shop
 * orders go to the commerce payment provider.
 */
class StripeWebhookController extends CashierWebhookController
{
    public function __construct(
        private readonly BillingWebhookProcessor $processor,
        private readonly StripeProvider $commerce,
        private readonly StripeSignatureVerifier $signatures,
    ) {}

    public function __invoke(Request $request): Response
    {
        if ($this->signatures->secrets() === []) {
            return new Response('Webhook signing secret not configured', 500);
        }

        if (! $this->signatures->verify($request)) {
            return new Response('Invalid signature', 400);
        }

        return parent::handleWebhook($request);
    }

    protected function handleInvoicePaymentSucceeded(array $payload): Response
    {
        return $this->billOnce($payload, function () use ($payload): void {
            $this->processor->handleInvoicePaymentSucceeded($payload);
        });
    }

    protected function handleInvoicePaymentFailed(array $payload): Response
    {
        return $this->billOnce($payload, function () use ($payload): void {
            $this->processor->handleInvoicePaymentFailed($payload);
        });
    }

    protected function handleCustomerSubscriptionDeleted(array $payload): Response
    {
        return $this->billOnce($payload, function () use ($payload): void {
            $this->processor->handleCustomerSubscriptionDeleted($payload);
        });
    }

    protected function handleCustomerSubscriptionTrialWillEnd(array $payload): Response
    {
        return $this->billOnce($payload, function () use ($payload): void {
            $this->processor->handleCustomerSubscriptionTrialWillEnd($payload);
        });
    }

    protected function handleCheckoutSessionCompleted(array $payload): Response
    {
        return $this->forwardToCommerce($payload);
    }

    protected function handleCheckoutSessionAsyncPaymentSucceeded(array $payload): Response
    {
        return $this->forwardToCommerce($payload);
    }

    protected function handleCheckoutSessionAsyncPaymentFailed(array $payload): Response
    {
        return $this->forwardToCommerce($payload);
    }

    protected function handleCheckoutSessionExpired(array $payload): Response
    {
        return $this->forwardToCommerce($payload);
    }

    protected function handleRefundCreated(array $payload): Response
    {
        return $this->forwardToCommerce($payload);
    }

    protected function handleRefundUpdated(array $payload): Response
    {
        return $this->forwardToCommerce($payload);
    }

    protected function handleRefundFailed(array $payload): Response
    {
        return $this->forwardToCommerce($payload);
    }

    protected function handleChargeRefunded(array $payload): Response
    {
        return $this->forwardToCommerce($payload);
    }

    protected function handleChargeRefundUpdated(array $payload): Response
    {
        return $this->forwardToCommerce($payload);
    }

    /**
     * A subscription event that was already kept is acknowledged and not applied again.
     * Staff can still run it from the archive; that path does not come through here.
     *
     * @param  array<string, mixed>  $payload
     * @param  Closure(): void  $handle
     */
    private function billOnce(array $payload, Closure $handle): Response
    {
        if ($this->alreadyHandled($payload)) {
            return new Response('Webhook already handled', 200);
        }

        $handle();

        return new Response('Webhook handled', 200);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function alreadyHandled(array $payload): bool
    {
        $id = Arr::get($payload, 'id');

        if (! is_string($id) || $id === '') {
            return false;
        }

        return BillingWebhookCall::query()
            ->where('provider', 'stripe')
            ->where('external_id', $id)
            ->whereNotNull('processed_at')
            ->exists();
    }

    /**
     * A non-2xx response (a failure worth retrying) makes Stripe redeliver the event.
     */
    private function forwardToCommerce(array $payload): Response
    {
        $outcome = $this->commerce->processEvent($payload);

        return new Response($outcome->message, $outcome->httpStatus);
    }
}
