<?php

namespace App\Payments\Providers;

use App\Models\BillingWebhookCall;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Payments\Capability;
use App\Payments\Contracts\PaymentProvider;
use App\Payments\Data\CheckoutClosure;
use App\Payments\Data\CheckoutContext;
use App\Payments\Data\CheckoutSession;
use App\Payments\Data\RefundOutcome;
use App\Payments\Data\WebhookOutcome;
use App\Payments\Exceptions\BuyerIdentityRejected;
use App\Payments\Exceptions\PaymentException;
use App\Payments\Tebex\TebexBasketBuilder;
use App\Payments\Tebex\TebexCheckoutMode;
use App\Payments\Tebex\TebexClient;
use App\Payments\Tebex\TebexEvent;
use App\Payments\Tebex\TebexPayments;
use App\Payments\Tebex\TebexSignIn;
use App\Payments\Tebex\TebexStoreProfile;
use App\Payments\Tebex\TebexWebhookVerifier;
use App\Payments\Webhooks\WebhookReceiver;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Sells Tebex packages: the order's lines are put in a Tebex basket, and the customer pays on Tebex's hosted checkout.
 *
 * Tebex is the merchant of record. It works out tax, applies its own coupons, creator codes and gift cards, and takes
 * the payment, so what the buyer pays can differ from what the shop quoted; the order carries the shop's quote (the
 * packages at their price before tax), and once Tebex reports the payment the order and the payment say what was really
 * charged.
 *
 * **Settling an order is the webhook's job.** Tebex does not report a payment's outcome when asked, only by webhook
 * (`POST /webhooks/tebex`, see {@see TebexPayments} for what each delivery does), so an order is never marked paid from
 * anything else: not the buyer coming back to the shop, and not Tebex.js in the browser. That is why this provider says
 * it is not configured until the webhook's secret is set. Recurring payments are a later slice.
 */
class TebexProvider implements PaymentProvider
{
    public const KEY = 'tebex';

    public function __construct(
        private readonly TebexClient $client,
        private readonly TebexStoreProfile $profile,
        private readonly TebexBasketBuilder $baskets,
        private readonly TebexSignIn $signIn,
        private readonly TebexWebhookVerifier $webhooks,
        private readonly TebexPayments $payments,
        private readonly WebhookReceiver $receiver,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Tebex';
    }

    public function capabilities(): array
    {
        return [
            Capability::ONE_TIME_PAYMENTS,
            Capability::HOSTED_CHECKOUT,
        ];
    }

    /**
     * There is a token, the store is one the shop can sell for (see {@see TebexStoreProfile}), and the webhook has a
     * secret, since a payment Tebex took could never be applied without it.
     */
    public function isConfigured(): bool
    {
        return $this->client->isConfigured() && $this->profile->isSupported() && $this->webhooks->isConfigured();
    }

    /**
     * Make a basket for the order and say where the buyer goes next.
     *
     * The basket carries the order's public id and a random value (the nonce, kept on the payment), which is what the
     * payment's webhook will be matched to the order by. What happens next depends on how the store identifies buyers
     * (see {@see TebexStoreProfile}):
     *
     * - **Nobody is asked anything:** the packages go in, and the buyer goes to pay.
     * - **A username is asked for:** it goes into the basket as it is made, Tebex says whether it recognised it (if not,
     *   the buyer is told on the form), then the packages go in and the buyer goes to pay.
     * - **The buyer signs in:** Tebex will not take a package before they have, so the buyer is sent to the shop's own
     *   link ({@see TebexSignIn}), which sends them to sign in and, when they are back, fills the basket and sends them to
     *   pay. That link is the payment's checkout URL, so starting again from the same order comes back to it.
     *
     * @throws BuyerIdentityRejected When Tebex does not recognise the username the buyer gave.
     */
    public function startCheckout(Order $order, CheckoutContext $context): CheckoutSession
    {
        // Whatever the page said, no basket is made for a store whose buyers the shop does not know how to take through.
        if ($reason = $this->profile->unsupportedReason()) {
            throw new PaymentException($reason);
        }

        $order->loadMissing('items.tebexPackage');

        if ($order->items->isEmpty()) {
            throw new PaymentException("Order {$order->number} has nothing in it to sell.");
        }

        foreach ($order->items as $item) {
            if (blank($item->tebexPackage?->ident)) {
                throw new PaymentException("Order {$order->number} has a line that is not a Tebex package the shop can put in a basket.");
            }
        }

        $identity = $this->profile->identity();
        $username = null;

        if ($identity === TebexStoreProfile::USERNAME) {
            $username = trim((string) $context->buyerUsername);

            if ($username === '') {
                throw new BuyerIdentityRejected('Enter your '.strtolower((string) $this->profile->usernameLabel()).'.');
            }
        }

        $nonce = Str::random(32);

        $basket = $this->client->createBasket(
            $context->successUrl,
            $context->cancelUrl,
            ['order' => $order->public_id, 'nonce' => $nonce],
            $context->ipAddress,
            $username,
        );

        if ($identity === TebexStoreProfile::USERNAME && ! $basket->isAuthorised()) {
            throw new BuyerIdentityRejected('Tebex did not recognise that '.strtolower((string) $this->profile->usernameLabel()).'. Please check it and try again.');
        }

        if ($identity === TebexStoreProfile::SIGN_IN) {
            // The packages go in once they are signed in; what Tebex quotes is kept then.
            return new CheckoutSession(
                provider: self::KEY,
                reference: $basket->ident,
                redirectUrl: $this->signIn->linkFor($order),
                expiresAt: $context->expiresAt,
                raw: ['nonce' => $nonce, 'sign_in' => true],
            );
        }

        $basket = $this->baskets->fill($order, $basket, $nonce);

        if ($basket->checkoutUrl === null) {
            throw new PaymentException("Tebex did not say where to pay for order {$order->number}.");
        }

        if ($basket->currency !== strtoupper($order->currency)) {
            throw new PaymentException("Tebex priced order {$order->number} in {$basket->currency}, not {$order->currency}.");
        }

        return new CheckoutSession(
            provider: self::KEY,
            reference: $basket->ident,
            // Tebex's own page, or (when the checkout is embedded) the shop's link, which shows Tebex.js on the shop's page and
            // is also where the buyer comes back to if they leave. Its checkout address is read again there.
            redirectUrl: TebexCheckoutMode::current()->isEmbedded() ? $this->signIn->linkFor($order) : $basket->checkoutUrl,
            expiresAt: $context->expiresAt,
            raw: [
                'nonce' => $nonce,
                ...$this->baskets->quote($order, $basket),
                'buyer' => $this->baskets->buyer($basket),
            ],
        );
    }

    /**
     * Make an unpaid basket unpayable, and say whether it is.
     *
     * Tebex has no call that cancels a basket, so the packages are taken out of it: a basket with nothing in it has
     * nothing to pay for. A basket that is already paid cannot be closed, and is left for the webhook to settle.
     *
     * Done while nobody else is filling the basket (a buyer coming back from signing in as the checkout is replaced),
     * since packages put in after it was emptied would make a closed checkout payable again.
     *
     * @throws PaymentException When Tebex cannot be asked, or the basket is in use by another request (nothing is done).
     */
    public function closeCheckout(Payment $payment): CheckoutClosure
    {
        return $this->baskets->exclusively($payment->provider_reference, function () use ($payment): CheckoutClosure {
            $basket = $this->client->basket($payment->provider_reference);

            if ($basket->isPaid()) {
                return CheckoutClosure::Unresolved;
            }

            foreach ($basket->packages as $package) {
                $basket = $this->client->removePackage($basket->ident, (string) $package['id']);
            }

            // Asked again rather than trusted: what counts is that Tebex says it is empty and unpaid.
            $basket = $this->client->basket($payment->provider_reference);

            return ! $basket->isPaid() && $basket->packages === []
                ? CheckoutClosure::Closed
                : CheckoutClosure::Unresolved;
        });
    }

    /**
     * Nothing to ask: Tebex does not report a payment's outcome when asked, only by webhook, and an order is never
     * settled from anything else. (A late or lost delivery is made up for by Tebex retrying it.)
     */
    public function reconcile(Payment $payment): void
    {
        // Intentionally empty. See the class comment.
    }

    /**
     * Check a delivery is Tebex's and apply it. The endpoint has already refused senders at other addresses.
     *
     * Tebex first sends a validation delivery, and will not send anything else until it is answered with the delivery's
     * own id. Everything after that is applied once: deliveries are kept under Tebex's id for them, so a retry changes
     * nothing.
     */
    public function handleWebhook(Request $request): WebhookOutcome
    {
        if (! $this->webhooks->isConfigured()) {
            return WebhookOutcome::failed('Webhook secret not configured');
        }

        if (! $this->webhooks->isSigned($request)) {
            return WebhookOutcome::rejected('Invalid signature');
        }

        $data = json_decode($request->getContent(), true);
        $event = is_array($data) ? TebexEvent::fromArray($data) : null;

        if ($event === null) {
            return WebhookOutcome::rejected('Malformed payload');
        }

        if ($event->type === TebexEvent::VALIDATION) {
            return WebhookOutcome::answered(['id' => $event->id]);
        }

        return $this->processEvent($event);
    }

    /**
     * Apply a delivery whose signature has already been checked.
     */
    public function processEvent(TebexEvent $event): WebhookOutcome
    {
        return $this->receiver->receive(self::KEY, $event->id, $event->type, $event->redacted(), fn (BillingWebhookCall $call) => $this->payments->apply($event, $call));
    }

    public function refund(Refund $refund): RefundOutcome
    {
        throw new PaymentException('The shop cannot refund through Tebex yet: only providers that say they can are asked to.');
    }

    public function syncRefunds(Payment $payment): void
    {
        // Intentionally empty: Tebex does not list a payment's refunds when asked; a refund made in the Tebex panel
        // arrives as a `payment.refunded` delivery.
    }

    public function paymentUrl(Payment $payment): ?string
    {
        return null;
    }
}
