<?php

namespace App\Support\Commerce;

use App\Enums\OrderStatus;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\GiftCard;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Support\Commerce\GiftCards\GiftCards;
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
        private readonly GiftCards $giftCards,
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
     * What the order would be, priced exactly as {@see self::place()} will price it (strictly, so
     * anything incomplete or no longer offered is refused), without writing or reserving anything. Lets a
     * caller find out that an order cannot be placed before it does anything that cannot be undone.
     *
     * @throws CheckoutException When something in the cart cannot be bought.
     * @throws InvalidCheckoutInput When an address or shipping method cannot be accepted.
     */
    public function preview(Cart $cart, CustomerDetails $customer, CheckoutInput $input): Pricing
    {
        return $this->price($cart, $customer, $input);
    }

    private function price(Cart $cart, CustomerDetails $customer, CheckoutInput $input, bool $countOwnHolds = true): Pricing
    {
        return $this->pricer->price(
            $cart,
            $input->shippingAddress ? Destination::make($input->shippingAddress->country, $input->shippingAddress->region) : null,
            $input->billingAddress ? Destination::make($input->billingAddress->country, $input->billingAddress->region) : null,
            $input->shippingRateId,
            strict: true,
            customer: $customer,
            countOwnHolds: $countOwnHolds,
        );
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

                // The same for a gift card: two checkouts spending one card take turns, and the second prices
                // from what the first left on it.
                if ($cart->gift_card_id !== null) {
                    $cart->setRelation('giftCard', GiftCard::query()->whereKey($cart->gift_card_id)->lockForUpdate()->first());
                }

                // Whatever this cart's earlier checkout held on the card has been given back by now.
                $pricing = $this->price($cart, $customer, $input, countOwnHolds: false);

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
                    'gift_card_id' => $pricing->giftCardTotal()->minor > 0 ? $pricing->giftCard?->id : null,
                    'gift_card_total' => $pricing->giftCardTotal()->toDecimal(),
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
                        'tebex_package_id' => $line->item->tebex_package_id,
                        'quantity' => $line->item->quantity,
                        'unit_price' => $line->unit->toDecimal(),
                        'subtotal' => $line->subtotal->toDecimal(),
                        'discount_total' => ($line->discount ?? Money::zero($pricing->currency))->toDecimal(),
                        // Remembered, so a paid order made only of digital lines can be completed without shipping.
                        'requires_shipping' => $line->requiresShipping,
                        // Remembered too: the order owes a licence key for exactly the lines sold as one.
                        'delivers_keys' => (bool) InventoryItem::resolveFor($line->item->product_id, $line->item->product_variant_id)?->delivers_keys,
                        'tax_total' => $line->tax->toDecimal(),
                        'description' => $line->description,
                        'metadata' => $line->metadata,
                    ]);
                }

                // The card's share is taken now, so it cannot be spent twice while the rest is being paid, and
                // given back if the order is cancelled. (Before the stock is held: card first, then stock.)
                if ($pricing->giftCardTotal()->minor > 0 && $pricing->giftCard !== null) {
                    $this->giftCards->redeem($pricing->giftCard, $pricing->giftCardTotal(), $order);

                    OrderEvent::record($order, OrderEvent::GIFT_CARD_APPLIED, "Gift card ending {$pricing->giftCard->last_four} pays {$pricing->giftCardTotal()->toDecimal()} {$order->currency} of the total");
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
