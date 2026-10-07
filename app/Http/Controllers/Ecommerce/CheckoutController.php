<?php

namespace App\Http\Controllers\Ecommerce;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Payments\PaymentManager;
use App\Support\Commerce\CartManager;
use App\Support\Commerce\CheckoutException;
use App\Support\Commerce\CheckoutStarter;
use App\Support\Commerce\CustomerDetails;
use App\Support\Commerce\OrderAlreadyPaidException;
use App\Support\Seo\Seo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

class CheckoutController extends Controller
{
    public function show(Request $request, CheckoutStarter $starter, PaymentManager $payments): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user === null && ! config('commerce.checkout.guest')) {
            return redirect()->guest(route('login'));
        }

        $cart = CartManager::forRequest($request);

        if ($cart === null || $cart->items->isEmpty()) {
            return redirect()->route('shop.cart')->with('error', 'Your cart is empty.');
        }

        return Inertia::render('commerce/Checkout', [
            'cart' => CartManager::summary($cart),
            'customer' => [
                'email' => $user?->email,
                'name' => $user?->nickname,
            ],
            'isGuest' => $user === null,
            'provider' => $payments->active()->label(),
            'available' => $starter->isAvailable(),
            // Identifies this form, so submitting it twice cannot place two orders.
            'token' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request, CheckoutStarter $starter): SymfonyResponse|RedirectResponse
    {
        $user = $request->user();

        if ($user === null && ! config('commerce.checkout.guest')) {
            return redirect()->guest(route('login'));
        }

        $validated = $request->validate([
            'email' => [$user ? 'nullable' : 'required', 'email:rfc', 'max:255'],
            'name' => ['nullable', 'string', 'max:120'],
            'token' => ['required', 'uuid'],
        ]);

        $cart = CartManager::forRequest($request);

        if ($cart === null || $cart->items->isEmpty()) {
            return redirect()->route('shop.cart')->with('error', 'Your cart is empty.');
        }

        $customer = new CustomerDetails(
            email: $user->email ?? $validated['email'],
            name: $validated['name'] ?? $user?->nickname,
            user: $user,
        );

        try {
            $result = $starter->start($cart, $customer, $this->idempotencyKey($cart->id, $validated['token']));
        } catch (OrderAlreadyPaidException $exception) {
            // The earlier attempt was paid after all: show that order rather than charge again.
            return redirect($starter->completeUrl($exception->order))->with('success', $exception->getMessage());
        } catch (CheckoutException $exception) {
            return redirect()->route('shop.cart')->with('error', $exception->getMessage());
        }

        // Off to the payment provider's own page: a full browser navigation, not an Inertia visit.
        return Inertia::location($result['url']);
    }

    /**
     * Where the customer lands after paying. Anyone holding the signed link
     * (a guest) or an owner of the order may open it.
     */
    public function complete(Request $request, Order $order, PaymentManager $payments): Response
    {
        abort_unless($request->hasValidSignature() || Gate::allows('view', $order), 403);

        // The webhook normally settles the order first. If it is late, ask the provider.
        if ($order->status === OrderStatus::Pending) {
            $payment = $order->payments()->latest('id')->first();

            if ($payment !== null && $payment->status === PaymentStatus::Pending) {
                try {
                    $payments->provider($payment->provider)->reconcile($payment);
                    $order->refresh();
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        }

        $order->load('items');

        app(Seo::class)->title('Order '.$order->number)->noindex();

        return Inertia::render('commerce/CheckoutComplete', [
            'order' => [
                'number' => $order->number,
                'status' => $order->status->value,
                'status_label' => $order->status->label(),
                'payment_status' => $order->payment_status->value,
                'payment_status_label' => $order->payment_status->label(),
                'currency' => $order->currency,
                'subtotal' => $order->subtotal,
                'tax_total' => $order->tax_total,
                'shipping_total' => $order->shipping_total,
                'discount_total' => $order->discount_total,
                'grand_total' => $order->grand_total,
                'customer_email' => $order->customer_email,
                'placed_at' => $order->placed_at?->toIso8601String(),
                'items' => $order->items->map(fn ($item) => [
                    'id' => $item->id,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'subtotal' => $item->subtotal,
                ])->values(),
            ],
        ]);
    }

    /**
     * Scoped to the cart so a token cannot be replayed against someone else's order.
     */
    private function idempotencyKey(int $cartId, string $token): string
    {
        return hash('sha256', $cartId.'|'.$token);
    }
}
