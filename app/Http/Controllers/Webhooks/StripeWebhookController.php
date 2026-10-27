<?php

namespace App\Http\Controllers\Webhooks;

use App\Models\BillingWebhookCall;
use App\Payments\Data\WebhookOutcome;
use App\Payments\Providers\StripeProvider;
use App\Payments\Stripe\StripeSignatureVerifier;
use App\Payments\Webhooks\WebhookReceiver;
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
    /**
     * Event ids whose handler is already running in this process. Two PHP workers
     * still take the row lock in {@see WebhookReceiver::receive()}; this only stops
     * a second delivery that arrives in this same process before the first finishes,
     * which cannot wait on that lock without deadlocking itself.
     *
     * @var array<string, true>
     */
    private static array $applying = [];

    public function __construct(
        private readonly BillingWebhookProcessor $processor,
        private readonly StripeProvider $commerce,
        private readonly StripeSignatureVerifier $signatures,
        private readonly WebhookReceiver $webhooks,
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
     * Claim the event and lock that row before the handler runs, as {@see WebhookReceiver::receive()}
     * does. A second delivery then finds the claim and does not apply the event again, including one
     * that arrives while the first is still at work. Staff can still run a kept event from the archive;
     * that path does not come through here.
     *
     * @param  array<string, mixed>  $payload
     * @param  Closure(): void  $handle
     */
    private function billOnce(array $payload, Closure $handle): Response
    {
        $id = Arr::get($payload, 'id');

        if (! is_string($id) || $id === '') {
            $handle();

            return new Response('Webhook handled', 200);
        }

        if (isset(self::$applying[$id])) {
            return new Response('Webhook already handled', 200);
        }

        $type = Arr::get($payload, 'type');

        self::$applying[$id] = true;

        try {
            $outcome = $this->webhooks->receive(
                StripeProvider::KEY,
                $id,
                is_string($type) && $type !== '' ? $type : 'unknown',
                $payload,
                function (BillingWebhookCall $call) use ($handle): ?WebhookOutcome {
                    $handle();

                    return null;
                },
            );
        } finally {
            unset(self::$applying[$id]);
        }

        return new Response($outcome->message, $outcome->httpStatus);
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
