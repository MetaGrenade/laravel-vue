<?php

namespace App\Payments\Stripe;

use Laravel\Cashier\Cashier;

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

    public function expireCheckoutSession(string $id): void
    {
        Cashier::stripe()->checkout->sessions->expire($id);
    }
}
