<?php

namespace App\Payments\Providers;

use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Payments\Capability;
use App\Payments\Contracts\PaymentProvider;
use App\Payments\Data\CheckoutClosure;
use App\Payments\Data\CheckoutContext;
use App\Payments\Data\CheckoutSession;
use App\Payments\Data\ProviderRefund;
use App\Payments\Data\RefundOutcome;
use App\Payments\Data\WebhookOutcome;
use App\Payments\Exceptions\PaymentException;
use App\Payments\Exceptions\RefundRejected;
use App\Payments\Stripe\StripeGateway;
use App\Payments\Stripe\StripeSignatureVerifier;
use App\Payments\Webhooks\WebhookReceiver;
use App\Support\Commerce\Money;
use App\Support\Commerce\OrderLifecycle;
use App\Support\Commerce\OrderRefunder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Takes one-time payments for shop orders with hosted Stripe Checkout.
 *
 * Prices and totals always come from our own order, never from the customer:
 * the session is built from the order's lines, and when Stripe reports the
 * payment the amount is compared with the order before anything is marked paid.
 * (Subscriptions for plans still go through Cashier; see SubscriptionManager.)
 */
class StripeProvider implements PaymentProvider
{
    public const KEY = 'stripe';

    /**
     * Webhook events this provider acts on.
     *
     * @var list<string>
     */
    public const EVENTS = [
        'checkout.session.completed',
        'checkout.session.async_payment_succeeded',
        'checkout.session.async_payment_failed',
        'checkout.session.expired',
    ];

    /**
     * Events that say a refund changed. They only tell us which payment to look at: the
     * refunds themselves are then read from Stripe, so the shape of the event does not matter.
     * Subscribe the webhook endpoint to these as well as to the checkout events.
     *
     * @var list<string>
     */
    public const REFUND_EVENTS = [
        'refund.created',
        'refund.updated',
        'refund.failed',
        'charge.refunded',
        'charge.refund.updated',
    ];

    public function __construct(
        private readonly StripeGateway $stripe,
        private readonly StripeSignatureVerifier $signatures,
        private readonly WebhookReceiver $webhooks,
        private readonly OrderLifecycle $lifecycle,
        private readonly OrderRefunder $refunds,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Stripe';
    }

    public function capabilities(): array
    {
        return [
            Capability::ONE_TIME_PAYMENTS,
            Capability::SUBSCRIPTIONS,
            Capability::HOSTED_CHECKOUT,
            Capability::REFUNDS,
        ];
    }

    public function isConfigured(): bool
    {
        return filled(config('cashier.secret')) && $this->signatures->secrets() !== [];
    }

    public function startCheckout(Order $order, CheckoutContext $context): CheckoutSession
    {
        $order->loadMissing('items');

        $lines = [];
        $linesTotal = Money::zero($order->currency);

        foreach ($order->items as $item) {
            $unit = Money::parse($item->unit_price, $order->currency);
            $net = $unit->multiply($item->quantity)->subtract(Money::parse($item->discount_total, $order->currency));
            $linesTotal = $linesTotal->add($net);

            foreach ($this->itemLines($item->description ?: 'Item', $item->quantity, $unit, $net, $order->currency) as $line) {
                $lines[] = $line;
            }
        }

        // Everything on the order that is not a product line is sent as its own line, so
        // the customer pays exactly what the order says: shipping (less any the discount code
        // waived), then each tax.
        $shipping = Money::parse($order->shipping_total, $order->currency)
            ->subtract(Money::parse($order->metadata['discount']['shipping'] ?? 0, $order->currency));

        if (! $shipping->isZero()) {
            $lines[] = $this->extraLine('Shipping'.($order->shipping_method ? " — {$order->shipping_method}" : ''), $shipping, $order->currency);
            $linesTotal = $linesTotal->add($shipping);
        }

        foreach ((array) ($order->metadata['tax_lines'] ?? []) as $taxLine) {
            $tax = Money::parse($taxLine['amount'] ?? 0, $order->currency);

            if ($tax->isZero()) {
                continue;
            }

            $lines[] = $this->extraLine("{$taxLine['name']} ({$taxLine['rate']}%)", $tax, $order->currency);
            $linesTotal = $linesTotal->add($tax);
        }

        // Anything not sent as a line would make the totals differ, so refuse rather than charge
        // a different amount than the order says.
        if (! $linesTotal->equals(Money::parse($order->grand_total, $order->currency))) {
            throw new PaymentException("Order {$order->number} total does not match its line items.");
        }

        $metadata = [
            'source' => 'commerce',
            'order_id' => (string) $order->id,
            'order_number' => (string) $order->number,
            'order_public_id' => $order->public_id,
        ];

        try {
            $session = $this->stripe->createCheckoutSession(array_filter([
                'mode' => 'payment',
                'client_reference_id' => $order->public_id,
                'customer_email' => $order->customer_email,
                'line_items' => $lines,
                'success_url' => $context->successUrl,
                'cancel_url' => $context->cancelUrl,
                'expires_at' => $context->expiresAt->getTimestamp(),
                'locale' => $context->locale,
                'metadata' => $metadata,
                'payment_intent_data' => array_filter([
                    'metadata' => $metadata,
                    'shipping' => $this->shippingDetails($order),
                ]),
            ]), "order-{$order->public_id}-checkout");
        } catch (Throwable $exception) {
            throw new PaymentException('Stripe could not create the checkout: '.$exception->getMessage(), previous: $exception);
        }

        $id = Arr::get($session, 'id');
        $url = Arr::get($session, 'url');

        if (! is_string($id) || ! is_string($url)) {
            throw new PaymentException('Stripe returned a checkout without an id or URL.');
        }

        $expiresAt = Arr::get($session, 'expires_at');

        return new CheckoutSession(
            provider: self::KEY,
            reference: $id,
            redirectUrl: $url,
            expiresAt: is_numeric($expiresAt) ? Carbon::createFromTimestamp((int) $expiresAt) : $context->expiresAt,
            raw: ['id' => $id, 'status' => Arr::get($session, 'status')],
        );
    }

    public function closeCheckout(Payment $payment): CheckoutClosure
    {
        $payment->loadMissing('order');

        try {
            $session = $this->stripe->expireCheckoutSession($payment->provider_reference);
        } catch (Throwable $exception) {
            // Stripe only expires an open session. If it is already paid or expired the
            // call is refused, so ask what state it is in instead of assuming.
            try {
                $session = $this->stripe->retrieveCheckoutSession($payment->provider_reference);
            } catch (Throwable $retrieval) {
                throw new PaymentException(
                    'Could not confirm whether the Stripe checkout is closed: '.$retrieval->getMessage(),
                    previous: $exception,
                );
            }
        }

        if (Arr::get($session, 'payment_status') === 'paid') {
            $this->applyPaid($payment, $session);

            return $payment->order->refresh()->isPaid() ? CheckoutClosure::Paid : CheckoutClosure::Unresolved;
        }

        return match (Arr::get($session, 'status')) {
            'expired' => CheckoutClosure::Closed,
            // Completed but not paid yet: a bank debit still settling. It cannot be cancelled.
            'complete' => CheckoutClosure::Unresolved,
            // Still open although expiring it failed (or an unknown state): it is not closed,
            // and saying otherwise would let a replacement be offered next to it.
            default => throw new PaymentException('The Stripe checkout is still open and could not be expired.'),
        };
    }

    public function reconcile(Payment $payment): void
    {
        if ($payment->status !== PaymentStatus::Pending) {
            return;
        }

        $session = $this->stripe->retrieveCheckoutSession($payment->provider_reference);

        if (Arr::get($session, 'payment_status') === 'paid') {
            $this->applyPaid($payment, $session);
        } elseif (Arr::get($session, 'status') === 'expired') {
            $this->lifecycle->cancel($payment->order, 'expired');
        }
    }

    public function handleWebhook(Request $request): WebhookOutcome
    {
        if (! $this->signatures->verify($request)) {
            return WebhookOutcome::rejected('Invalid signature');
        }

        $event = json_decode($request->getContent(), true);

        return is_array($event) ? $this->processEvent($event) : WebhookOutcome::rejected('Malformed payload');
    }

    /**
     * Apply a Stripe event whose signature has already been verified.
     *
     * @param  array<string, mixed>  $event
     */
    public function processEvent(array $event): WebhookOutcome
    {
        $id = Arr::get($event, 'id');
        $type = Arr::get($event, 'type');
        $session = Arr::get($event, 'data.object');

        if (! is_string($id) || ! is_string($type) || ! is_array($session)) {
            return WebhookOutcome::rejected('Malformed event');
        }

        if (in_array($type, self::REFUND_EVENTS, true)) {
            return $this->processRefundEvent($id, $type, $event, $session);
        }

        // Plan subscriptions also use Checkout; only sessions we created for an order are ours.
        if (! in_array($type, self::EVENTS, true) || Arr::get($session, 'metadata.source') !== 'commerce') {
            return WebhookOutcome::ignored();
        }

        return $this->webhooks->receive(self::KEY, $id, $type, $event, fn () => $this->apply($type, $session));
    }

    /**
     * A refund event is ours when it is about a payment this shop took. Refunds of
     * subscription invoices, or of anything else on the account, are not.
     *
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $object  A refund, or for charge.refunded the charge.
     */
    private function processRefundEvent(string $id, string $type, array $event, array $object): WebhookOutcome
    {
        $intent = Arr::get($object, 'payment_intent');

        $payment = is_string($intent) && $intent !== ''
            ? Payment::query()
                ->with('order')
                ->where('provider', self::KEY)
                ->where('provider_payment_id', $intent)
                ->first()
            : null;

        if ($payment === null || $payment->order === null) {
            return WebhookOutcome::ignored('Not a refund of a shop payment');
        }

        return $this->webhooks->receive(self::KEY, $id, $type, $event, function () use ($payment) {
            $this->syncRefunds($payment);

            return WebhookOutcome::handled();
        });
    }

    public function refund(Refund $refund): RefundOutcome
    {
        $refund->loadMissing(['payment', 'order']);

        $payment = $refund->payment;

        if ($payment === null || blank($payment->provider_payment_id)) {
            throw new PaymentException('The payment has no Stripe PaymentIntent to refund.');
        }

        $reason = in_array($refund->reason, ['duplicate', 'fraudulent', 'requested_by_customer'], true) ? $refund->reason : null;

        try {
            $result = $this->stripe->createRefund(array_filter([
                'payment_intent' => $payment->provider_payment_id,
                'amount' => $refund->money()->minor,
                'reason' => $reason,
                // How a refund is recognised later, even if Stripe's reply to this call is lost.
                'metadata' => [
                    'source' => 'commerce',
                    'refund_id' => (string) $refund->id,
                    'order_number' => (string) $refund->order?->number,
                ],
            ]), $refund->idempotency_key);
        } catch (RefundRejected $exception) {
            return RefundOutcome::refused($exception->getMessage());
        } catch (Throwable $exception) {
            throw new PaymentException('Stripe did not confirm the refund: '.$exception->getMessage(), previous: $exception);
        }

        $reference = Arr::get($result, 'id');

        if (! is_string($reference)) {
            throw new PaymentException('Stripe answered the refund without an id.');
        }

        return RefundOutcome::of(
            $this->refundStatus(Arr::get($result, 'status')),
            $reference,
            $this->failureOf($result),
        );
    }

    public function syncRefunds(Payment $payment): void
    {
        $payment->loadMissing('order');

        if (blank($payment->provider_payment_id) || $payment->order === null) {
            return;
        }

        // A refund can only follow a payment we know about; if the payment's own message
        // has not arrived yet, ask for it first.
        if ($payment->status === PaymentStatus::Pending) {
            $this->reconcile($payment);
            $payment->refresh();
        }

        try {
            $remote = $this->stripe->listRefunds($payment->provider_payment_id);
        } catch (Throwable $exception) {
            throw new PaymentException('Could not read the refunds from Stripe: '.$exception->getMessage(), previous: $exception);
        }

        $refunds = [];

        foreach ($remote as $item) {
            $reference = Arr::get($item, 'id');
            $amount = Arr::get($item, 'amount');
            $currency = Arr::get($item, 'currency');

            if (! is_string($reference) || ! is_int($amount) || ! is_string($currency)) {
                continue;
            }

            try {
                $money = Money::ofMinor($amount, $currency);
            } catch (InvalidArgumentException) {
                continue;
            }

            $ours = Arr::get($item, 'metadata.refund_id');
            $reason = Arr::get($item, 'reason');

            $refunds[] = new ProviderRefund(
                reference: $reference,
                amount: $money,
                status: $this->refundStatus(Arr::get($item, 'status')),
                reason: is_string($reason) ? $reason : null,
                failureReason: $this->failureOf($item),
                refundId: is_numeric($ours) ? (int) $ours : null,
            );
        }

        $this->refunds->sync($payment, $refunds);
    }

    public function paymentUrl(Payment $payment): ?string
    {
        if (blank($payment->provider_payment_id)) {
            return null;
        }

        $mode = str_starts_with((string) config('cashier.secret'), 'sk_test_') ? 'test/' : '';

        return "https://dashboard.stripe.com/{$mode}payments/{$payment->provider_payment_id}";
    }

    /**
     * Stripe's refund statuses in ours. A refund that needs the customer or the bank
     * to act (requires_action) is still on its way, so it stays pending.
     */
    private function refundStatus(mixed $status): RefundStatus
    {
        return match ($status) {
            'succeeded' => RefundStatus::Succeeded,
            'failed' => RefundStatus::Failed,
            'canceled' => RefundStatus::Canceled,
            default => RefundStatus::Pending,
        };
    }

    /**
     * @param  array<string, mixed>  $refund
     */
    private function failureOf(array $refund): ?string
    {
        $reason = Arr::get($refund, 'failure_reason') ?? (Arr::get($refund, 'status') === 'requires_action' ? 'requires_action' : null);

        return is_string($reason) ? $reason : null;
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function apply(string $type, array $session): WebhookOutcome
    {
        $payment = Payment::query()
            ->with('order')
            ->where('provider', self::KEY)
            ->where('provider_reference', (string) Arr::get($session, 'id'))
            ->first();

        if ($payment === null || $payment->order === null) {
            Log::warning('Stripe reported a checkout session this shop has no payment for.', [
                'session' => Arr::get($session, 'id'),
            ]);

            return WebhookOutcome::ignored('Unknown checkout session');
        }

        // The session must have been created for the order we have on record.
        if (Arr::get($session, 'client_reference_id') !== $payment->order->public_id) {
            Log::critical('Stripe session does not belong to the order it is recorded against.', [
                'session' => Arr::get($session, 'id'),
                'order' => $payment->order->number,
            ]);

            return WebhookOutcome::ignored('Session does not match order');
        }

        switch ($type) {
            case 'checkout.session.completed':
                if (Arr::get($session, 'payment_status') === 'paid') {
                    $this->applyPaid($payment, $session);
                } else {
                    // Asynchronous methods (bank debits) confirm later.
                    $payment->forceFill(['provider_payment_id' => Arr::get($session, 'payment_intent')])->save();
                }

                break;

            case 'checkout.session.async_payment_succeeded':
                $this->applyPaid($payment, $session);

                break;

            case 'checkout.session.async_payment_failed':
                $this->lifecycle->markPaymentFailed($payment->order, $payment);

                break;

            case 'checkout.session.expired':
                $this->lifecycle->cancel($payment->order, 'expired');

                break;
        }

        return WebhookOutcome::handled();
    }

    /**
     * Mark the order paid, but only if Stripe took exactly what the order costs.
     *
     * @param  array<string, mixed>  $session
     */
    private function applyPaid(Payment $payment, array $session): void
    {
        $expected = Money::parse($payment->amount, $payment->currency);

        try {
            $received = Money::ofMinor((int) Arr::get($session, 'amount_total', -1), (string) Arr::get($session, 'currency', ''));
        } catch (InvalidArgumentException) {
            $received = null;
        }

        if ($received === null || ! $received->equals($expected)) {
            // Stripe builds the amount from the lines we sent, so this should be
            // impossible. Do not fulfil the order; leave it for a person.
            $payment->forceFill([
                'status' => PaymentStatus::Review,
                'provider_payment_id' => Arr::get($session, 'payment_intent'),
                'raw' => $this->summarise($session),
            ])->save();

            Log::critical('Stripe reported a payment that does not match the order total; it was not applied.', [
                'order' => $payment->order->number,
                'session' => $payment->provider_reference,
                'expected' => $expected->toDecimal().' '.$expected->currency,
                'received_minor' => Arr::get($session, 'amount_total'),
                'received_currency' => Arr::get($session, 'currency'),
            ]);

            return;
        }

        $this->lifecycle->markPaid($payment->order, $payment, [
            'provider_payment_id' => Arr::get($session, 'payment_intent'),
            'raw' => $this->summarise($session),
        ]);
    }

    /**
     * A line for something that is not a product (shipping, a tax).
     *
     * @return array<string, mixed>
     */
    private function extraLine(string $name, Money $amount, string $currency): array
    {
        return [
            'quantity' => 1,
            'price_data' => [
                'currency' => strtolower($currency),
                'unit_amount' => $amount->minor,
                'product_data' => ['name' => Str::limit($name, 250, '')],
            ],
        ];
    }

    /**
     * The Checkout lines for one product line, which must add up to what the customer pays for it
     * ($net) while keeping the quantity they ordered.
     *
     * Without a discount that is one line. A discount can leave a price that does not divide
     * evenly between the units (three at 10.00 with 1.00 off is 29.00), and Checkout takes no
     * negative lines and no fractions of a cent, so the units are split into two lines whose prices
     * differ by one minor unit: here one at 9.66 and two at 9.67. The total is exact.
     *
     * @return list<array<string, mixed>>
     */
    private function itemLines(string $name, int $quantity, Money $unit, Money $net, string $currency): array
    {
        $line = fn (int $count, int $amount): array => [
            'quantity' => $count,
            'price_data' => [
                'currency' => strtolower($currency),
                'unit_amount' => $amount,
                'product_data' => ['name' => Str::limit($name, 250, '')],
            ],
        ];

        if ($net->equals($unit->multiply($quantity))) {
            return [$line($quantity, $unit->minor)];
        }

        $each = intdiv($net->minor, $quantity);
        $dearer = $net->minor % $quantity;

        return array_values(array_filter([
            $quantity - $dearer > 0 ? $line($quantity - $dearer, $each) : null,
            $dearer > 0 ? $line($dearer, $each + 1) : null,
        ]));
    }

    /**
     * Where the order ships, in the shape Stripe records on the PaymentIntent so it shows
     * in the dashboard. Null when nothing is shipped.
     *
     * @return array<string, mixed>|null
     */
    private function shippingDetails(Order $order): ?array
    {
        $address = $order->shipping_address;

        if (! is_array($address) || blank(Arr::get($address, 'line1'))) {
            return null;
        }

        return array_filter([
            'name' => Arr::get($address, 'name'),
            'phone' => Arr::get($address, 'phone'),
            'address' => array_filter([
                'line1' => Arr::get($address, 'line1'),
                'line2' => Arr::get($address, 'line2'),
                'city' => Arr::get($address, 'city'),
                'state' => Arr::get($address, 'region'),
                'postal_code' => Arr::get($address, 'postal_code'),
                'country' => Arr::get($address, 'country'),
            ]),
        ]);
    }

    /**
     * Keep ids and amounts, not the customer's details.
     *
     * @param  array<string, mixed>  $session
     * @return array<string, mixed>
     */
    private function summarise(array $session): array
    {
        return Arr::only($session, ['id', 'status', 'payment_status', 'amount_total', 'currency', 'payment_intent']);
    }
}
