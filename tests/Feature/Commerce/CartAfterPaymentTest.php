<?php

namespace Tests\Feature\Commerce;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Commerce\CartManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithCommerce;
use Tests\TestCase;

/**
 * An order is a snapshot of the cart at the moment checkout started. If the
 * shopper keeps editing the cart while the payment is pending, paying must
 * consume only what was ordered, not everything in the cart.
 */
class CartAfterPaymentTest extends TestCase
{
    use InteractsWithCommerce;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerce();
        Notification::fake();
    }

    private function pay(Payment $payment): void
    {
        $this->deliverStripeEvent($this->stripeEvent('checkout.session.completed', $this->sessionFor($payment)))->assertOk();
    }

    #[Test]
    public function an_untouched_cart_is_converted_when_its_order_is_paid(): void
    {
        [$order, $payment] = $this->placeOrder(quantity: 2);

        $this->pay($payment);

        $this->assertSame(CartManager::CONVERTED, Cart::find($order->cart_id)->status);
        $this->assertSame(0, CartItem::where('cart_id', $order->cart_id)->count());
    }

    #[Test]
    public function products_added_while_the_payment_was_pending_stay_in_the_cart(): void
    {
        [$order, $payment] = $this->placeOrder(price: '25.00', quantity: 2);
        $later = Product::factory()->priced('7.00')->create(['name' => 'Added later']);

        // The shopper comes back from Stripe's page, adds something, then pays the original session.
        $this->addToCart($later)->assertRedirect();
        $this->pay($payment);

        $cart = Cart::find($order->cart_id);
        $this->assertSame(CartManager::OPEN, $cart->status, 'there is still something to buy');
        $this->assertSame(['Added later'], $cart->items()->get()->map(fn (CartItem $item) => $item->snapshot['product']['name'])->all());
        $this->assertSame('7.00', $cart->fresh()->subtotal);

        // It is the cart the shopper sees next, and the paid order holds only what was bought.
        $this->get(route('shop.cart'))->assertInertia(fn (Assert $page) => $page
            ->where('cart.id', $cart->id)
            ->where('cart.count', 1)
            ->where('cart.items.0.name', 'Added later'));
        $this->assertSame(['25.00'], $order->items()->pluck('unit_price')->all());
        $this->assertSame(1, $order->items()->count());
    }

    #[Test]
    public function a_quantity_raised_after_checkout_keeps_the_difference(): void
    {
        [$order, $payment] = $this->placeOrder(price: '10.00', quantity: 2);
        $line = CartItem::where('cart_id', $order->cart_id)->sole();

        // Two were ordered; the shopper bumps the cart to five before the payment lands.
        $this->patch(route('shop.cart.items.update', $line), ['quantity' => 5])->assertRedirect();
        $this->pay($payment);

        $line->refresh();
        $this->assertSame(3, $line->quantity);
        $this->assertSame('30.00', $line->total);
        $this->assertSame('30.00', Cart::find($order->cart_id)->subtotal);
        $this->assertSame(CartManager::OPEN, Cart::find($order->cart_id)->status);
    }

    #[Test]
    public function a_quantity_lowered_below_what_was_ordered_does_not_leave_a_negative_line(): void
    {
        [$order, $payment] = $this->placeOrder(price: '10.00', quantity: 3);
        $line = CartItem::where('cart_id', $order->cart_id)->sole();

        $this->patch(route('shop.cart.items.update', $line), ['quantity' => 1])->assertRedirect();
        $this->pay($payment);

        $this->assertSame(0, CartItem::where('cart_id', $order->cart_id)->count());
        $this->assertSame(CartManager::CONVERTED, Cart::find($order->cart_id)->status);
    }

    #[Test]
    public function a_line_removed_from_the_cart_before_payment_is_not_an_error(): void
    {
        [$order, $payment] = $this->placeOrder(price: '10.00', quantity: 1);
        $other = Product::factory()->priced('4.00')->create();

        $this->delete(route('shop.cart.items.destroy', CartItem::where('cart_id', $order->cart_id)->sole()))->assertRedirect();
        $this->addToCart($other)->assertRedirect();
        $this->pay($payment);

        $this->assertTrue($order->fresh()->isPaid());
        $this->assertSame(1, CartItem::where('cart_id', $order->cart_id)->count());
        $this->assertSame('4.00', Cart::find($order->cart_id)->subtotal);
    }

    #[Test]
    public function another_variant_of_the_same_product_is_left_alone(): void
    {
        $product = Product::factory()->priced('10.00')->stocked(20)->create();
        $small = ProductVariant::factory()->for($product)->priced('10.00')->create(['name' => 'Small']);
        $large = ProductVariant::factory()->for($product)->priced('12.00')->create(['name' => 'Large']);

        $this->addToCart($small, 2)->assertRedirect();
        $this->post(route('shop.checkout.store'), ['email' => 'a@example.com', 'token' => (string) Str::uuid()])->assertRedirect();
        $order = Order::sole();

        $this->addToCart($large)->assertRedirect();
        $this->pay($order->payments()->firstOrFail());

        $remaining = CartItem::where('cart_id', $order->cart_id)->get();
        $this->assertCount(1, $remaining);
        $this->assertSame($large->id, $remaining->first()->product_variant_id);
        $this->assertSame(CartManager::OPEN, Cart::find($order->cart_id)->status);
    }
}
