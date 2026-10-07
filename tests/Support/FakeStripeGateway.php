<?php

namespace Tests\Support;

use App\Payments\Stripe\StripeGateway;
use RuntimeException;
use Throwable;

/**
 * Stands in for Stripe in tests: remembers what it was asked and plays back
 * Checkout Sessions the way Stripe would (including repeating the same session
 * for a repeated idempotency key).
 */
class FakeStripeGateway implements StripeGateway
{
    /** @var list<array{params: array<string, mixed>, key: string}> */
    public array $created = [];

    /** @var list<string> */
    public array $expired = [];

    /** @var array<string, array<string, mixed>> */
    public array $sessions = [];

    /** @var array<string, string> */
    private array $byKey = [];

    public ?Throwable $failCreateWith = null;

    public ?Throwable $failRetrieveWith = null;

    public function createCheckoutSession(array $params, string $idempotencyKey): array
    {
        if ($this->failCreateWith !== null) {
            throw $this->failCreateWith;
        }

        if (isset($this->byKey[$idempotencyKey])) {
            return $this->sessions[$this->byKey[$idempotencyKey]];
        }

        $this->created[] = ['params' => $params, 'key' => $idempotencyKey];

        $id = 'cs_test_'.count($this->created);
        $total = 0;

        foreach ($params['line_items'] as $line) {
            $total += $line['price_data']['unit_amount'] * $line['quantity'];
        }

        $this->sessions[$id] = [
            'id' => $id,
            'object' => 'checkout.session',
            'url' => "https://checkout.stripe.test/c/pay/{$id}",
            'status' => 'open',
            'payment_status' => 'unpaid',
            'mode' => 'payment',
            'amount_total' => $total,
            'currency' => $params['line_items'][0]['price_data']['currency'],
            'client_reference_id' => $params['client_reference_id'],
            'expires_at' => $params['expires_at'],
            'metadata' => $params['metadata'],
            'payment_intent' => null,
        ];
        $this->byKey[$idempotencyKey] = $id;

        return $this->sessions[$id];
    }

    public function retrieveCheckoutSession(string $id): array
    {
        if ($this->failRetrieveWith !== null) {
            throw $this->failRetrieveWith;
        }

        return $this->sessions[$id] ?? throw new RuntimeException("No such session {$id}");
    }

    public function expireCheckoutSession(string $id): void
    {
        $this->expired[] = $id;
    }

    /**
     * Pretend the customer paid on Stripe's page.
     *
     * @return array<string, mixed>
     */
    public function pay(string $id): array
    {
        $this->sessions[$id]['status'] = 'complete';
        $this->sessions[$id]['payment_status'] = 'paid';
        $this->sessions[$id]['payment_intent'] = 'pi_test_'.$id;

        return $this->sessions[$id];
    }

    /**
     * @return array<string, mixed>
     */
    public function lastCreatedParams(): array
    {
        return $this->created[array_key_last($this->created)]['params'];
    }
}
