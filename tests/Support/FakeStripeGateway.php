<?php

namespace Tests\Support;

use App\Payments\Exceptions\RefundRejected;
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

    /** Sessions that were successfully expired. @var list<string> */
    public array $expired = [];

    /**
     * Every call in the order it happened (for example "create:cs_test_1", "expire:cs_test_1"),
     * so tests can assert that one thing happened before another.
     *
     * @var list<string>
     */
    public array $calls = [];

    /** @var array<string, array<string, mixed>> */
    public array $sessions = [];

    /** @var array<string, string> */
    private array $byKey = [];

    public ?Throwable $failCreateWith = null;

    public ?Throwable $failRetrieveWith = null;

    public ?Throwable $failExpireWith = null;

    /** Refunds Stripe holds, by id. @var array<string, array<string, mixed>> */
    public array $refunds = [];

    /** The status new refunds get ("succeeded", "pending", "failed"). */
    public string $nextRefundStatus = 'succeeded';

    /** Throw before Stripe sees the request (a dropped connection): nothing is created. */
    public ?Throwable $failRefundWith = null;

    /** Create the refund, then lose the reply: the caller sees an error but Stripe has it. */
    public bool $loseRefundReply = false;

    public ?Throwable $failListRefundsWith = null;

    /** @var array<string, string> */
    private array $refundsByKey = [];

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
        $this->calls[] = "create:{$id}";

        return $this->sessions[$id];
    }

    public function retrieveCheckoutSession(string $id): array
    {
        $this->calls[] = "retrieve:{$id}";

        if ($this->failRetrieveWith !== null) {
            throw $this->failRetrieveWith;
        }

        return $this->sessions[$id] ?? throw new RuntimeException("No such session {$id}");
    }

    public function expireCheckoutSession(string $id): array
    {
        $this->calls[] = "expire:{$id}";

        if ($this->failExpireWith !== null) {
            throw $this->failExpireWith;
        }

        // Like Stripe, only an open session can be expired.
        if (($this->sessions[$id]['status'] ?? null) !== 'open') {
            throw new RuntimeException('Only Checkout Sessions with a status in open can be expired.');
        }

        $this->sessions[$id]['status'] = 'expired';
        $this->expired[] = $id;

        return $this->sessions[$id];
    }

    public function createRefund(array $params, string $idempotencyKey): array
    {
        $this->calls[] = "refund:{$params['payment_intent']}";

        if ($this->failRefundWith !== null) {
            throw $this->failRefundWith;
        }

        if (isset($this->refundsByKey[$idempotencyKey])) {
            return $this->refunds[$this->refundsByKey[$idempotencyKey]];
        }

        $refund = $this->storeRefund(
            $params['payment_intent'],
            $params['amount'],
            $this->nextRefundStatus,
            $params['reason'] ?? null,
            $params['metadata'] ?? [],
        );

        $this->refundsByKey[$idempotencyKey] = $refund['id'];

        if ($this->loseRefundReply) {
            throw new RuntimeException('Connection reset while waiting for Stripe.');
        }

        return $refund;
    }

    public function listRefunds(string $paymentIntentId): array
    {
        $this->calls[] = "list-refunds:{$paymentIntentId}";

        if ($this->failListRefundsWith !== null) {
            throw $this->failListRefundsWith;
        }

        return array_values(array_filter(
            $this->refunds,
            fn (array $refund) => $refund['payment_intent'] === $paymentIntentId,
        ));
    }

    /**
     * A refund made in Stripe's dashboard: the shop never asked for it.
     *
     * @return array<string, mixed>
     */
    public function refundExternally(string $paymentIntentId, int $amount, string $status = 'succeeded'): array
    {
        return $this->storeRefund($paymentIntentId, $amount, $status, 'requested_by_customer', []);
    }

    /**
     * @return array<string, mixed>
     */
    public function setRefundStatus(string $refundId, string $status, ?string $failureReason = null): array
    {
        $this->refunds[$refundId]['status'] = $status;
        $this->refunds[$refundId]['failure_reason'] = $failureReason;

        return $this->refunds[$refundId];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function storeRefund(string $paymentIntentId, int $amount, string $status, ?string $reason, array $metadata): array
    {
        $session = collect($this->sessions)->first(fn (array $session) => $session['payment_intent'] === $paymentIntentId);

        if ($session === null) {
            throw new RefundRejected("No such payment_intent: '{$paymentIntentId}'");
        }

        $held = collect($this->refunds)
            ->filter(fn (array $refund) => $refund['payment_intent'] === $paymentIntentId && in_array($refund['status'], ['succeeded', 'pending'], true))
            ->sum('amount');

        // Like Stripe, never more than is left on the charge.
        if ($amount > $session['amount_total'] - $held) {
            throw new RefundRejected("Refund amount ({$amount}) is greater than unrefunded amount on charge");
        }

        $id = 're_test_'.(count($this->refunds) + 1);

        return $this->refunds[$id] = [
            'id' => $id,
            'object' => 'refund',
            'amount' => $amount,
            'currency' => $session['currency'],
            'payment_intent' => $paymentIntentId,
            'status' => $status,
            'reason' => $reason,
            'failure_reason' => null,
            'metadata' => $metadata,
        ];
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
