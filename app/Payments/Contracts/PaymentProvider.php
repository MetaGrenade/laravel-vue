<?php

namespace App\Payments\Contracts;

use App\Models\Order;
use App\Models\Payment;
use App\Payments\Capability;
use App\Payments\Data\CheckoutContext;
use App\Payments\Data\CheckoutSession;
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
     * Best-effort: stop the provider accepting payment on an abandoned checkout.
     */
    public function cancelCheckout(Payment $payment): void;

    /**
     * Ask the provider what happened to a payment and apply it. Used when a
     * webhook may be late or lost, and before expiring an unpaid order.
     */
    public function reconcile(Payment $payment): void;

    /**
     * Verify and apply a webhook delivery. Must be idempotent.
     */
    public function handleWebhook(Request $request): WebhookOutcome;
}
