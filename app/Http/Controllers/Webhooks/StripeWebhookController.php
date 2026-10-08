<?php

namespace App\Http\Controllers\Webhooks;

use App\Payments\Providers\StripeProvider;
use App\Payments\Stripe\StripeSignatureVerifier;
use App\Support\Billing\BillingWebhookProcessor;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
     * A non-2xx response (a failure worth retrying) makes Stripe redeliver the event.
     */
    private function forwardToCommerce(array $payload): Response
    {
        $outcome = $this->commerce->processEvent($payload);

        return new Response($outcome->message, $outcome->httpStatus);
    }
}
