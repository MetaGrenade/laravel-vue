<?php

namespace App\Support\Commerce;

use App\Enums\OrderStatus;
use App\Models\Cart;
use App\Models\Coupon;
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
     * @param  (callable(Pricing): void)|null  $accept  Run once the order has been priced and before anything is
     *                                                  written or reserved: it throws to refuse the order.
     *
     * @throws CheckoutException When something in the cart cannot be bought.
     * @throws InvalidCheckoutInput When an address or shipping method cannot be accepted.
     */
    public function place(
        Cart $cart,
        CustomerDetails $customer,
        string $provider,
        CheckoutInput $input,
        ?string $idempotencyKey = null,
        ?callable $accept = null,
    ): Order {
        try {
            return DB::transaction(function () use ($cart, $customer, $provider, $input, $idempotencyKey, $accept) {
                // Two checkouts racing for the last use of a discount code take turns here: the second
                // waits for the first to commit, then counts again and finds the code used up.
                if ($cart->coupon_id !== null) {
                    $cart->setRelation('coupon', Coupon::query()->whereKey($cart->coupon_id)->lockForUpdate()->first());
                }

                $pricing = $this->pricer->price(
                    $cart,
                    $input->shippingAddress ? Destination::make($input->shippingAddress->country, $input->shippingAddress->region) : null,
                    $input->billingAddress ? Destination::make($input->billingAddress->country, $input->billingAddress->region) : null,
                    $input->shippingRateId,
                    strict: true,
                    customer: $customer,
                );

                if ($accept !== null) {
                    $accept($pricing);
                }

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
                    'coupon_id' => $pricing->applied?->coupon->id,
                    'coupon_code' => $pricing->applied?->coupon->code,
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
                        // What the code was worth on this order, kept as it was in case the coupon is edited later.
                        'discount' => $pricing->applied?->toRecord(),
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
                        'discount_total' => ($line->discount ?? Money::zero($pricing->currency))->toDecimal(),
                        // Remembered, so a paid order made only of digital lines can be completed without shipping.
                        'requires_shipping' => $line->requiresShipping,
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
