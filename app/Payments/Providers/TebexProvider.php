<?php

namespace App\Payments\Providers;

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
use App\Payments\Tebex\TebexClient;
use App\Payments\Tebex\TebexSignIn;
use App\Payments\Tebex\TebexStoreProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Sells Tebex packages: the order's lines are put in a Tebex basket, and the customer pays on Tebex's hosted checkout.
 *
 * Tebex is the merchant of record. It works out tax, applies its own coupons, creator codes and gift cards, and takes
 * the payment, so what the buyer pays can differ from what the shop quoted; the order carries the shop's quote (the
 * packages at their price before tax), and the payment Tebex's own figures once it reports them.
 *
 * **Settling an order is not built yet.** Tebex tells the shop a payment happened by webhook, and the endpoint that
 * receives it (and the refunds, disputes and recurring payments that follow) is the next slice. Until then an order
 * placed through this provider stays pending and expires, so the provider is registered only when
 * `TEBEX_CHECKOUT_PREVIEW` is set (see docs/tebex.md), for trying a basket against a real store, never for taking money.
 */
class TebexProvider implements PaymentProvider
{
    public const KEY = 'tebex';

    public function __construct(
        private readonly TebexClient $client,
        private readonly TebexStoreProfile $profile,
        private readonly TebexBasketBuilder $baskets,
        private readonly TebexSignIn $signIn,
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
     * There is a token, and the store is one the shop can sell for (see {@see TebexStoreProfile}).
     */
    public function isConfigured(): bool
    {
        return $this->client->isConfigured() && $this->profile->isSupported();
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

        $basket = $this->baskets->fill($order, $basket);

        if ($basket->checkoutUrl === null) {
            throw new PaymentException("Tebex did not say where to pay for order {$order->number}.");
        }

        if ($basket->currency !== strtoupper($order->currency)) {
            throw new PaymentException("Tebex priced order {$order->number} in {$basket->currency}, not {$order->currency}.");
        }

        return new CheckoutSession(
            provider: self::KEY,
            reference: $basket->ident,
            redirectUrl: $basket->checkoutUrl,
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
     */
    public function closeCheckout(Payment $payment): CheckoutClosure
    {
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
    }

    /**
     * Nothing to ask: Tebex does not report a payment's outcome when asked, only by webhook, and an order is never
     * settled from anything else.
     */
    public function reconcile(Payment $payment): void
    {
        // Intentionally empty. See the class comment.
    }

    public function handleWebhook(Request $request): WebhookOutcome
    {
        throw new PaymentException('The shop does not handle Tebex webhooks yet.');
    }

    public function refund(Refund $refund): RefundOutcome
    {
        throw new PaymentException('The shop cannot refund through Tebex yet: only providers that say they can are asked to.');
    }

    public function syncRefunds(Payment $payment): void
    {
        // Intentionally empty: refunds made in the Tebex panel are not read back yet.
    }

    public function paymentUrl(Payment $payment): ?string
    {
        return null;
    }
}
