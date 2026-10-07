<?php

namespace App\Http\Controllers\Ecommerce;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Commerce\CheckoutRequest;
use App\Models\Address;
use App\Models\Cart;
use App\Models\Order;
use App\Models\TaxRate;
use App\Models\User;
use App\Payments\PaymentManager;
use App\Support\Commerce\AddressBook;
use App\Support\Commerce\CartManager;
use App\Support\Commerce\CheckoutException;
use App\Support\Commerce\CheckoutStarter;
use App\Support\Commerce\Countries;
use App\Support\Commerce\CustomerDetails;
use App\Support\Commerce\Destination;
use App\Support\Commerce\InvalidCheckoutInput;
use App\Support\Commerce\OrderAlreadyPaidException;
use App\Support\Commerce\OrderPricer;
use App\Support\Commerce\ShippingCalculator;
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
    public function show(
        Request $request,
        CheckoutStarter $starter,
        PaymentManager $payments,
        OrderPricer $pricer,
        ShippingCalculator $shipping,
        AddressBook $addressBook,
    ): Response|RedirectResponse {
        $user = $request->user();

        if ($user === null && ! config('commerce.checkout.guest')) {
            return redirect()->guest(route('login'));
        }

        $cart = CartManager::forRequest($request);

        if ($cart === null || $cart->items->isEmpty()) {
            return redirect()->route('shop.cart')->with('error', 'Your cart is empty.');
        }

        $needsShipping = $pricer->cartNeedsShipping($cart);
        $saved = $user ? $addressBook->for($user) : collect();

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
            'needsShipping' => $needsShipping,
            // Digital goods ship nowhere; a billing address is only needed when tax depends on location.
            'billingRequired' => ! $needsShipping && TaxRate::query()->active()->exists(),
            'countries' => $needsShipping ? ($shipping->servedCountries() ?? Countries::codes()) : Countries::codes(),
            'addresses' => $saved->map(fn (Address $address) => $this->presentAddress($address))->values(),
            // Recalculated by partial reloads as the shopper fills in their address and picks a method.
            // The page pre-selects the default saved address (the first one), so it is priced from the start.
            'quote' => fn () => $this->quote($request, $cart, $pricer, $saved->first()),
        ]);
    }

    public function store(CheckoutRequest $request, CheckoutStarter $starter, AddressBook $addressBook): SymfonyResponse|RedirectResponse
    {
        $user = $request->user();

        if ($user === null && ! config('commerce.checkout.guest')) {
            return redirect()->guest(route('login'));
        }

        $cart = $request->cart();

        if ($cart === null || $cart->items->isEmpty()) {
            return redirect()->route('shop.cart')->with('error', 'Your cart is empty.');
        }

        $customer = new CustomerDetails(
            email: $user->email ?? $request->validated('email'),
            name: $request->validated('name') ?? $user?->nickname,
            user: $user,
        );

        try {
            $result = $starter->start(
                $cart,
                $customer,
                $request->checkoutInput(),
                $this->idempotencyKey($cart->id, $request->validated('token')),
            );
        } catch (OrderAlreadyPaidException $exception) {
            // The earlier attempt was paid after all: show that order rather than charge again.
            return redirect($starter->completeUrl($exception->order))->with('success', $exception->getMessage());
        } catch (InvalidCheckoutInput $exception) {
            // Fixed on the form (an address we cannot ship to, a method no longer offered): stay here.
            return back()->withInput()->withErrors([$exception->field => $exception->getMessage()]);
        } catch (CheckoutException $exception) {
            return redirect()->route('shop.cart')->with('error', $exception->getMessage());
        }

        $this->rememberAddresses($request, $user, $addressBook);

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
                'shipping_method' => $order->shipping_method,
                'shipping_address' => $order->shipping_address,
                'billing_address' => $order->billing_address,
                'tax_lines' => $order->metadata['tax_lines'] ?? [],
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
     * The totals for what the shopper has entered so far. Only the country and
     * region matter for shipping and tax, so they are all the browser sends.
     *
     *
     * @return array<string, mixed>
     */
    private function quote(Request $request, Cart $cart, OrderPricer $pricer, ?Address $preselected = null): array
    {
        // The browser always names the shipping country once the form is live (empty while a new
        // address is still being typed). Only a first visit, with nothing named, falls back to
        // the saved address the page selects for the customer.
        $shipTo = $request->has('ship_country')
            ? Destination::make($request->query('ship_country'), $request->query('ship_region'))
            : ($preselected ? Destination::make($preselected->country, $preselected->region) : null);

        try {
            $pricing = $pricer->price(
                $cart,
                $shipTo,
                Destination::make($request->query('bill_country'), $request->query('bill_region')),
                $request->filled('rate') ? $request->integer('rate') : null,
            );
        } catch (CheckoutException $exception) {
            return ['error' => $exception->getMessage()];
        }

        return $pricing->toArray() + ['error' => null];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentAddress(Address $address): array
    {
        return [
            'id' => $address->id,
            'label' => $address->label,
            'is_default' => $address->is_default,
            'name' => $address->name,
            'company' => $address->company,
            'line1' => $address->line1,
            'line2' => $address->line2,
            'city' => $address->city,
            'region' => $address->region,
            'postal_code' => $address->postal_code,
            'country' => $address->country,
            'phone' => $address->phone,
        ];
    }

    /**
     * Save the addresses a signed-in customer typed in, if they asked to.
     */
    private function rememberAddresses(CheckoutRequest $request, ?User $user, AddressBook $addressBook): void
    {
        if ($user === null || ! $request->boolean('save_addresses')) {
            return;
        }

        foreach (['shipping', 'billing'] as $kind) {
            $typed = $request->typedAddress($kind);

            if ($typed !== null) {
                $addressBook->remember($user, $typed);
            }
        }
    }

    /**
     * Scoped to the cart so a token cannot be replayed against someone else's order.
     */
    private function idempotencyKey(int $cartId, string $token): string
    {
        return hash('sha256', $cartId.'|'.$token);
    }
}
