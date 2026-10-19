<?php

namespace App\Payments\Tebex;

use App\Models\Order;
use App\Models\Payment;
use App\Payments\Exceptions\PaymentException;
use Illuminate\Support\Facades\URL;

/**
 * The middle of checking out for a store whose buyers have to sign in (Discord, FiveM, Rust and the other Steam-based
 * games): Tebex will not take a package into a basket until the buyer has signed in to it, so the basket is made and
 * the buyer is sent to sign in, and the packages go in, and the buyer on to pay, when they come back.
 *
 * Every visit to the link works out where the buyer has got to from the basket itself, so it is safe to open twice, to
 * come back to after an hour, or to be sent to again after abandoning the sign-in.
 */
class TebexSignIn
{
    public function __construct(
        private readonly TebexClient $client,
        private readonly TebexBasketBuilder $baskets,
    ) {}

    /**
     * Where the link the buyer is on is, and so where they come back to after signing in.
     */
    public function linkFor(Order $order): string
    {
        return URL::signedRoute('shop.tebex.continue', ['order' => $order->public_id]);
    }

    /**
     * @throws TebexException When Tebex cannot be reached or does not answer sensibly.
     */
    public function next(Order $order, Payment $payment): SignInStep
    {
        $basket = $this->client->basket((string) $payment->provider_reference);

        // Paid already (a second visit after paying): nothing more to do here, and nothing to change.
        if ($basket->isPaid()) {
            return SignInStep::redirect(URL::signedRoute('shop.checkout.complete', ['order' => $order->public_id]));
        }

        if (! $basket->isAuthorised()) {
            $options = $this->client->signInOptions($basket->ident, $this->linkFor($order));

            return match (count($options)) {
                0 => SignInStep::failed('Tebex did not offer a way to sign in, so the payment cannot start. Please try again later.'),
                1 => SignInStep::redirect($options[0]['url']),
                default => SignInStep::choose($options),
            };
        }

        $basket = $this->baskets->fill($order, $basket);

        if ($basket->checkoutUrl === null) {
            throw new PaymentException("Tebex did not say where to pay for order {$order->number}.");
        }

        if ($basket->currency !== strtoupper($order->currency)) {
            throw new PaymentException("Tebex priced order {$order->number} in {$basket->currency}, not {$order->currency}.");
        }

        // What Tebex quoted and who it says the buyer is, now that both are known.
        $payment->forceFill(['raw' => [
            ...(array) $payment->raw,
            ...$this->baskets->quote($order, $basket),
            'buyer' => $this->baskets->buyer($basket),
        ]])->save();

        return SignInStep::redirect($basket->checkoutUrl);
    }
}
