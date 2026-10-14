<?php

namespace Tests\Support;

use App\Enums\OrderStatus;
use App\Models\InventoryItem;
use App\Models\LicenceKey;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Commerce\Digital\LicenceKeys;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;

/**
 * Helpers for tests that sell licence keys as a guest through the real checkout and the real Stripe
 * webhook. Use together with {@see InteractsWithCommerce}.
 */
trait SellsLicenceKeys
{
    protected function keyedProduct(int $keys, string $price = '15.00', string $name = 'Licence'): Product
    {
        return Product::factory()->priced($price)->digital()->keyed($keys)->create(['name' => $name]);
    }

    /**
     * Buy as the current guest, and pay.
     */
    protected function buy(Product|ProductVariant $purchasable, int $quantity = 1, bool $pay = true): Order
    {
        $this->cartWith($purchasable, $quantity);
        $this->post(route('shop.checkout.store'), $this->checkoutPayload())->assertRedirect();

        $order = Order::query()->latest('id')->firstOrFail();

        if ($pay) {
            $this->deliverStripeEvent($this->stripeEvent('checkout.session.completed', $this->sessionFor($order->payments()->sole())))->assertOk();
        }

        return $order->refresh();
    }

    protected function orderPage(Order $order): TestResponse
    {
        return $this->get(URL::signedRoute('shop.checkout.complete', ['order' => $order->public_id]));
    }

    protected function stockOf(Product|ProductVariant $purchasable): InventoryItem
    {
        $variant = $purchasable instanceof ProductVariant ? $purchasable : null;
        $product = $variant ? $variant->product : $purchasable;

        return InventoryItem::resolveFor($product->id, $variant?->id) ?? $this->fail('no stock record');
    }

    /**
     * The count is what is in the pool, less what orders have spoken for (waiting for payment, or paid
     * and still waiting for a key).
     */
    protected function assertStockFollowsPool(Product|ProductVariant $purchasable): void
    {
        $stock = $this->stockOf($purchasable)->refresh();

        $unused = LicenceKey::query()->where('inventory_item_id', $stock->id)->unused()->count();
        $pending = (int) OrderItem::query()
            ->where('delivers_keys', true)
            ->where('product_id', $stock->product_id)
            ->when($stock->product_variant_id, fn ($query, $variantId) => $query->where('product_variant_id', $variantId))
            ->whereHas('order', fn ($order) => $order->where('status', OrderStatus::Pending->value))
            ->sum('quantity');
        $owed = app(LicenceKeys::class)->counts($stock)['waiting'];

        $this->assertSame($unused - $pending - $owed, $stock->quantity, "the count ({$stock->quantity}) should be {$unused} unused - {$pending} on pending orders - {$owed} owed");
    }

    /**
     * The keys an order was given, in the order they were issued (staff-withdrawn ones included).
     *
     * @return list<string>
     */
    protected function keysOf(Order $order): array
    {
        return LicenceKey::query()
            ->whereIn('order_item_id', $order->items()->pluck('id'))
            ->orderBy('id')
            ->get()
            ->map(fn (LicenceKey $key) => (string) $key->value)
            ->all();
    }

    /**
     * A customer's payment arrives after their order was cancelled and the only key went to someone else:
     * the order is paid, so it is honoured, but there is nothing to give it yet.
     *
     * @return array{0: Product, 1: Order}
     */
    protected function lateOrderWithNoKeyLeft(): array
    {
        $product = $this->keyedProduct(1);
        $late = $this->buy($product, pay: false);
        $session = $this->sessionFor($late->payments()->sole(), paid: false);

        // The checkout expires (the unit goes back) and another customer takes the key.
        $this->deliverStripeEvent($this->stripeEvent('checkout.session.expired', $session))->assertOk();
        $this->buy($product);

        // The first customer's payment went through just as their order was being cancelled.
        $this->deliverStripeEvent($this->stripeEvent('checkout.session.completed', $this->sessionFor($late->payments()->sole())))->assertOk();

        return [$product, $late->refresh()];
    }
}
