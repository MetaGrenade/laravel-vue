<?php

namespace App\Http\Controllers\Ecommerce;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Payments\Exceptions\PaymentException;
use App\Payments\Providers\TebexProvider;
use App\Payments\Tebex\SignInStep;
use App\Payments\Tebex\TebexException;
use App\Payments\Tebex\TebexSignIn;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where a buyer of a store that makes them sign in (Discord, FiveM, Rust...) is sent from checkout, and where they come
 * back to after signing in: it works out how far they have got from the basket at Tebex, and sends them on to sign in
 * or, once they have, to pay (see {@see TebexSignIn}).
 *
 * The link is signed, so it can be opened by a guest and by no one who was not given it, and it is safe to open again.
 */
class TebexContinueController extends Controller
{
    public function __invoke(Request $request, Order $order, TebexSignIn $signIn): Response|RedirectResponse
    {
        abort_unless($order->payment_provider === TebexProvider::KEY, 404);

        $payment = $order->payments()->where('provider', TebexProvider::KEY)->latest('id')->first();

        abort_if($payment === null, 404);

        // Paid: this is what the buyer is looking for.
        if ($order->isPaid()) {
            return redirect()->to(URL::signedRoute('shop.checkout.complete', ['order' => $order->public_id]));
        }

        if ($order->status !== OrderStatus::Pending || ($order->expires_at !== null && $order->expires_at->isPast())) {
            return redirect()->route('shop.cart')->with('error', 'This checkout has expired. Please start again.');
        }

        try {
            $step = $signIn->next($order, $payment);
        } catch (TebexException $exception) {
            report($exception);

            return redirect()->route('shop.cart')->with('error', 'We could not reach Tebex just now. Please try again in a moment.');
        } catch (PaymentException $exception) {
            // Tebex answered, but with something the shop will not sell on: no place to pay, or another currency than the
            // order's. Trying the same link again would only meet the same answer.
            report($exception);

            return redirect()->route('shop.cart')->with('error', 'We could not start the payment. Please start the checkout again in a moment.');
        }

        return match ($step->kind) {
            SignInStep::REDIRECT => redirect()->away((string) $step->url),
            SignInStep::CHOOSE => Inertia::render('commerce/TebexSignIn', [
                'options' => $step->options,
                'order' => $order->number,
                'cancel' => route('shop.cart'),
            ]),
            default => redirect()->route('shop.cart')->with('error', (string) $step->message),
        };
    }
}
