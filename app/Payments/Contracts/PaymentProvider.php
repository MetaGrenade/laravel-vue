<?php

namespace App\Payments\Contracts;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Payments\Capability;
use App\Payments\Data\CheckoutClosure;
use App\Payments\Data\CheckoutContext;
use App\Payments\Data\CheckoutSession;
use App\Payments\Data\RefundOutcome;
use App\Payments\Data\WebhookOutcome;
use App\Payments\Exceptions\PaymentException;
use Illuminate\Http\Request;

/**
 * A way to take payment for an order. Stripe and Tebex are drivers behind this
 * contract; the shop never talks to either directly.
 */
interface PaymentProvider
{
    /**
     * Stable identifier stored on orders and payments ("stripe", "tebex").
     */
    public function key(): string;

    public function label(): string;

    /**
     * What this provider can do, as {@see Capability} values.
     *
     * @return list<string>
     */
    public function capabilities(): array;

    /**
     * Whether credentials are present, so checkout can say so instead of failing.
     */
    public function isConfigured(): bool;

    /**
     * Create a checkout for the order at the provider. Must not change the
     * order: the caller records the returned session as a pending payment.
     *
     * @throws PaymentException
     */
    public function startCheckout(Order $order, CheckoutContext $context): CheckoutSession;

    /**
     * Stop the provider accepting payment on a checkout, and confirm that it did.
     *
     * A replacement checkout must never be offered while the one it replaces can
     * still be paid, so callers rely on the answer: Closed means no further
     * payment is possible; Paid means the customer had already paid (and the
     * payment was applied); Unresolved means it is neither, so leave the order.
     *
     * @throws PaymentException When the provider cannot be asked or its answer is unknown.
     */
    public function closeCheckout(Payment $payment): CheckoutClosure;

    /**
     * Ask the provider what happened to a payment and apply it. Used when a
     * webhook may be late or lost, and before expiring an unpaid order.
     */
    public function reconcile(Payment $payment): void;

    /**
     * Verify and apply a webhook delivery. Must be idempotent.
     */
    public function handleWebhook(Request $request): WebhookOutcome;

    /**
     * Ask the provider to send money back for a refund the shop has recorded
     * as pending. Only providers with {@see Capability::REFUNDS} are asked.
     *
     * Returns the provider's definite answer. Must be safe to repeat for the
     * same refund (the refund's idempotency key is sent along), because a call
     * whose result was lost is retried.
     *
     * @throws PaymentException When the answer is unknown (the provider could not be reached,
     *                          or did not say): the refund may or may not exist.
     */
    public function refund(Refund $refund): RefundOutcome;

    /**
     * Fetch the refunds the provider holds for a payment and bring the shop's
     * records in line, including refunds made outside the shop (in the
     * provider's dashboard). Must be idempotent.
     *
     * @throws PaymentException
     */
    public function syncRefunds(Payment $payment): void;

    /**
     * Where staff can see this payment in the provider's own dashboard, if there is such a place.
     */
    public function paymentUrl(Payment $payment): ?string;
}
