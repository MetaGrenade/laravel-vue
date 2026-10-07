<?php

namespace App\Payments\Stripe;

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
}
