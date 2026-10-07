<?php

namespace App\Support\Commerce;

use App\Enums\OrderStatus;
use App\Models\Cart;
use App\Models\Order;
use App\Support\Ownership;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Turns a cart into a pending order and holds its stock. Retiring the cart's
 * earlier unpaid orders is the caller's job ({@see CheckoutStarter}), because it
 * has to be confirmed with the payment provider first.
 *
 * The order is priced by {@see OrderPricer} in strict mode, so nothing from the
 * cart or the browser is trusted: products, prices, availability, the shipping
 * method and the tax are all worked out again here, and what is stored is
 * exactly what the customer will be charged.
 */
class OrderPlacer
{
    public function __construct(
        private readonly OrderPricer $pricer,
        private readonly InventoryReserver $inventory,
    ) {}

    /**
     * The order a previous submission with this key created, if any.
     */
    public function existing(?string $idempotencyKey): ?Order
    {
        return $idempotencyKey === null
            ? null
            : Order::query()->where('idempotency_key', $idempotencyKey)->first();
    }

    /**
     * @throws CheckoutException When something in the cart cannot be bought.
     * @throws InvalidCheckoutInput When an address or shipping method cannot be accepted.
     */
    public function place(
        Cart $cart,
        CustomerDetails $customer,
        string $provider,
        CheckoutInput $input,
        ?string $idempotencyKey = null,
    ): Order {
        try {
            return DB::transaction(function () use ($cart, $customer, $provider, $input, $idempotencyKey) {
                $pricing = $this->pricer->price(
                    $cart,
                    $input->shippingAddress ? Destination::make($input->shippingAddress->country, $input->shippingAddress->region) : null,
                    $input->billingAddress ? Destination::make($input->billingAddress->country, $input->billingAddress->region) : null,
                    $input->shippingRateId,
                    strict: true,
                );

                $shippingAddress = $pricing->needsShipping ? $input->shippingAddress : null;
                // Billing falls back to the shipping address when the customer did not give another.
                $billingAddress = $input->billingAddress ?? $shippingAddress;

                $order = new Order([
                    'user_id' => $customer->user?->id,
                    'cart_id' => $cart->id,
                    'status' => OrderStatus::Pending,
                    'payment_provider' => $provider,
                    'currency' => $pricing->currency,
                    'subtotal' => $pricing->subtotal->toDecimal(),
                    'tax_total' => $pricing->taxTotal()->toDecimal(),
                    'shipping_total' => $pricing->shippingTotal()->toDecimal(),
                    'discount_total' => $pricing->discount->toDecimal(),
                    'grand_total' => $pricing->grandTotal->toDecimal(),
                    'customer_email' => $customer->email,
                    'customer_name' => $customer->name,
                    'idempotency_key' => $idempotencyKey,
                    'shipping_method' => $pricing->shipping?->name,
                    'shipping_address' => $shippingAddress?->toArray(),
                    'billing_address' => $billingAddress?->toArray(),
                    'placed_at' => now(),
                    'expires_at' => now()->addMinutes((int) config('commerce.checkout.payment_window_minutes', 60)),
                    'metadata' => array_filter([
                        'shipping_rate_id' => $pricing->shipping?->id,
                        'shipping_tax' => $pricing->shippingTax->isZero() ? null : $pricing->shippingTax->toDecimal(),
                        'tax_lines' => $pricing->taxLines === [] ? null : array_map(fn (TaxLine $line) => $line->toArray(), $pricing->taxLines),
                    ], fn ($value) => $value !== null) ?: null,
                ]);

                $order->assignOwner($customer->user ? Ownership::newRecordOwnerFor($customer->user) : null);
                $order->save();

                foreach ($pricing->lines as $line) {
                    $order->items()->create([
                        'product_id' => $line->item->product_id,
                        'product_variant_id' => $line->item->product_variant_id,
                        'quantity' => $line->item->quantity,
                        'unit_price' => $line->unit->toDecimal(),
                        'subtotal' => $line->subtotal->toDecimal(),
                        'tax_total' => $line->tax->toDecimal(),
                        'description' => $line->description,
                        'metadata' => $line->metadata,
                    ]);
                }

                $order->load('items');
                $this->inventory->reserve($order);

                return $order;
            });
        } catch (UniqueConstraintViolationException $exception) {
            // The same form was submitted twice at once and the other request won.
            $existing = $this->existing($idempotencyKey);

            if ($existing === null) {
                throw $exception;
            }

            return $existing;
        }
    }
}
