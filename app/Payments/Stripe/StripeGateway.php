<?php

namespace App\Payments\Stripe;

use App\Payments\Exceptions\RefundRejected;

/**
 * The few Stripe API calls commerce makes. A narrow interface so tests (and any
 * future fake) do not have to imitate the whole Stripe SDK.
 */
interface StripeGateway
{
    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed> The Checkout Session.
     */
    public function createCheckoutSession(array $params, string $idempotencyKey): array;

    /**
     * @return array<string, mixed> The Checkout Session.
     */
    public function retrieveCheckoutSession(string $id): array;

    /**
     * @return array<string, mixed> The Checkout Session, now expired.
     *
     * @throws \Throwable When the session is not open (already paid or expired).
     */
    public function expireCheckoutSession(string $id): array;

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed> The Refund.
     *
     * @throws RefundRejected When Stripe refused the request, so nothing was refunded. Any other
     *                        throwable means the outcome is unknown: the refund may exist.
     */
    public function createRefund(array $params, string $idempotencyKey): array;

    /**
     * Every refund Stripe holds for a PaymentIntent, whoever created it.
     *
     * @return list<array<string, mixed>>
     */
    public function listRefunds(string $paymentIntentId): array;
}
