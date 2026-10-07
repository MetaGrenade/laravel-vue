<?php

namespace App\Support\Commerce;

use App\Enums\OrderStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Support\Ownership;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns a cart into a pending order and holds its stock.
 *
 * Nothing from the cart is trusted: the product, variant, price and
 * availability are looked up again, so a stale or tampered cart cannot buy
 * something at the wrong price or that has been withdrawn.
 */
class OrderPlacer
{
    public function __construct(
        private readonly PriceResolver $prices,
        private readonly InventoryReserver $inventory,
        private readonly OrderLifecycle $lifecycle,
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
     */
    public function place(Cart $cart, CustomerDetails $customer, string $provider, ?string $idempotencyKey = null): Order
    {
        $cart->load(['items.product', 'items.variant']);

        if ($cart->items->isEmpty()) {
            throw new CheckoutException('Your cart is empty.');
        }

        try {
            return DB::transaction(function () use ($cart, $customer, $provider, $idempotencyKey) {
                // Starting again replaces an earlier attempt: give its stock back first.
                Order::query()
                    ->where('cart_id', $cart->id)
                    ->where('status', OrderStatus::Pending->value)
                    ->get()
                    ->each(fn (Order $previous) => $this->lifecycle->cancel($previous, 'replaced'));

                $lines = $cart->items->map(fn (CartItem $item) => $this->line($item));

                $currency = $this->prices->currency();
                $subtotal = $lines->reduce(
                    fn (Money $carry, array $line) => $carry->add($line['subtotal']),
                    Money::zero($currency),
                );

                $order = new Order([
                    'user_id' => $customer->user?->id,
                    'cart_id' => $cart->id,
                    'status' => OrderStatus::Pending,
                    'payment_provider' => $provider,
                    'currency' => $currency,
                    'subtotal' => $subtotal->toDecimal(),
                    'tax_total' => '0.00',
                    'shipping_total' => '0.00',
                    'discount_total' => '0.00',
                    'grand_total' => $subtotal->toDecimal(),
                    'customer_email' => $customer->email,
                    'customer_name' => $customer->name,
                    'idempotency_key' => $idempotencyKey,
                    'placed_at' => now(),
                    'expires_at' => now()->addMinutes((int) config('commerce.checkout.payment_window_minutes', 60)),
                ]);

                $order->assignOwner($customer->user ? Ownership::newRecordOwnerFor($customer->user) : null);
                $order->save();

                foreach ($lines as $line) {
                    $order->items()->create([
                        'product_id' => $line['item']->product_id,
                        'product_variant_id' => $line['item']->product_variant_id,
                        'quantity' => $line['item']->quantity,
                        'unit_price' => $line['unit']->toDecimal(),
                        'subtotal' => $line['subtotal']->toDecimal(),
                        'description' => $line['description'],
                        'metadata' => $line['metadata'],
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

    /**
     * @return array{item: CartItem, unit: Money, subtotal: Money, description: string, metadata: array<string, mixed>}
     */
    private function line(CartItem $item): array
    {
        $product = $item->product;
        $variant = $item->variant;
        $name = $product !== null ? $product->name : ($item->snapshot['product']['name'] ?? 'This item');

        if ($product === null || ! $product->is_active) {
            throw new CheckoutException("{$name} is no longer available.");
        }

        if ($item->product_variant_id !== null && ($variant === null || $variant->product_id !== $product->id)) {
            throw new CheckoutException("{$name} is no longer available in the option you chose.");
        }

        $max = (int) config('commerce.checkout.max_quantity', 20);

        if ($item->quantity < 1 || $item->quantity > $max) {
            throw new CheckoutException("You can buy between 1 and {$max} of {$name} at a time.");
        }

        $price = $this->prices->resolve($product, $variant);

        if ($price === null) {
            throw new CheckoutException("{$name} can't be purchased right now.");
        }

        $unit = Money::parse($price->amount, $price->currency);
        $description = $variant ? "{$product->name} — {$variant->name}" : $product->name;

        return [
            'item' => $item,
            'unit' => $unit,
            'subtotal' => $unit->multiply($item->quantity),
            'description' => Str::limit($description, 250, ''),
            'metadata' => array_filter([
                'product' => $product->name,
                'variant' => $variant?->name,
                'sku' => $variant?->sku,
                'price_id' => $price->id,
            ], fn ($value) => $value !== null),
        ];
    }
}
