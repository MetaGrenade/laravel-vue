<?php

namespace App\Payments\Stripe;

use App\Payments\Exceptions\RefundRejected;
use Laravel\Cashier\Cashier;
use Stripe\Exception\ApiErrorException;

/**
 * Talks to Stripe with the same credentials Cashier uses.
 */
class CashierStripeGateway implements StripeGateway
{
    public function createCheckoutSession(array $params, string $idempotencyKey): array
    {
        return Cashier::stripe()->checkout->sessions
            ->create($params, ['idempotency_key' => $idempotencyKey])
            ->toArray();
    }

    public function retrieveCheckoutSession(string $id): array
    {
        return Cashier::stripe()->checkout->sessions->retrieve($id)->toArray();
    }

    public function expireCheckoutSession(string $id): array
    {
        return Cashier::stripe()->checkout->sessions->expire($id)->toArray();
    }

    public function createRefund(array $params, string $idempotencyKey): array
    {
        try {
            return Cashier::stripe()->refunds
                ->create($params, ['idempotency_key' => $idempotencyKey])
                ->toArray();
        } catch (ApiErrorException $exception) {
            // A 4xx that is not a conflict or a rate limit is Stripe saying no. Everything else
            // (a dropped connection, a 5xx, a request still in flight) leaves the outcome unknown.
            if (in_array($exception->getHttpStatus(), [400, 402, 403, 404], true)) {
                throw new RefundRejected($exception->getMessage(), previous: $exception);
            }

            throw $exception;
        }
    }

    public function listRefunds(string $paymentIntentId): array
    {
        $refunds = [];

        // Stripe returns at most 100 refunds a page. Follow the cursor to the end: a refund on an
        // earlier page is as real as one on the first, and missing it would leave a refund that
        // was made in the dashboard unrecorded, or an asynchronous one stuck as pending.
        $pages = Cashier::stripe()->refunds->all(['payment_intent' => $paymentIntentId, 'limit' => 100]);

        foreach ($pages->autoPagingIterator() as $refund) {
            $refunds[] = $refund->toArray();
        }

        return $refunds;
    }
}
