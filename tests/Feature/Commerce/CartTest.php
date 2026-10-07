<?php

namespace Tests\Feature\Commerce;

use App\Models\CartItem;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithCommerce;
use Tests\TestCase;

class CartTest extends TestCase
{
    use InteractsWithCommerce;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerce();
    }

    #[Test]
    public function a_guest_can_add_a_product_and_see_it_in_the_cart(): void
    {
        $product = Product::factory()->priced('19.99')->create(['name' => 'Hoodie']);

        $this->addToCart($product, 2)->assertRedirect();

        $this->get(route('shop.cart'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('commerce/Cart')
                ->where('cart.subtotal', '39.98')
                ->where('cart.count', 2)
                ->where('cart.items.0.name', 'Hoodie')
                ->where('cart.items.0.unit_price', '19.99')
                ->where('cart.items.0.total', '39.98'));
    }

    #[Test]
    public function adding_the_same_product_again_increases_the_quantity_up_to_the_limit(): void
    {
        config(['commerce.checkout.max_quantity' => 5]);
        $product = Product::factory()->priced('10.00')->create();

        $this->addToCart($product, 3);
        $this->addToCart($product, 4);

        $item = CartItem::sole();
        $this->assertSame(5, $item->quantity);
        $this->assertSame('50.00', $item->total);
    }

    #[Test]
    public function the_variant_price_is_used_when_the_variant_has_one(): void
    {
        $product = Product::factory()->priced('10.00')->create();
        $variant = ProductVariant::factory()->for($product)->priced('12.50')->create();

        $this->addToCart($variant);

        $this->assertSame('12.50', CartItem::sole()->unit_price);
    }

    #[Test]
    public function only_prices_in_the_store_currency_can_be_bought(): void
    {
        $product = Product::factory()->create();
        Price::factory()->for($product, 'priceable')->create(['amount' => '5.00', 'currency' => 'EUR']);

        $this->addToCart($product)->assertSessionHas('error');

        $this->assertSame(0, $this->cartItemCount());
    }

    #[Test]
    public function inactive_prices_and_products_cannot_be_bought(): void
    {
        $withoutActivePrice = Product::factory()->create();
        Price::factory()->for($withoutActivePrice, 'priceable')->create(['amount' => '5.00', 'is_active' => false]);
        $inactive = Product::factory()->inactive()->priced('5.00')->create();

        $this->addToCart($withoutActivePrice)->assertSessionHas('error');
        $this->addToCart($inactive)->assertSessionHas('error');

        $this->assertSame(0, $this->cartItemCount());
    }

    #[Test]
    public function a_variant_of_another_product_is_rejected(): void
    {
        $product = Product::factory()->priced('5.00')->create();
        $other = ProductVariant::factory()->priced('1.00')->create();

        $this->post(route('shop.cart.items.store'), [
            'product_id' => $product->id,
            'product_variant_id' => $other->id,
            'quantity' => 1,
        ])->assertNotFound();

        $this->assertSame(0, $this->cartItemCount());
    }

    #[Test]
    public function quantity_must_be_within_the_limit(): void
    {
        $product = Product::factory()->priced('5.00')->create();

        $this->addToCart($product, 0)->assertSessionHasErrors('quantity');
        $this->addToCart($product, 21)->assertSessionHasErrors('quantity');
    }

    #[Test]
    public function the_quantity_can_be_changed_and_totals_follow(): void
    {
        $product = Product::factory()->priced('3.33')->create();
        $this->addToCart($product, 1);
        $item = CartItem::sole();

        $this->patch(route('shop.cart.items.update', $item), ['quantity' => 3])->assertRedirect();

        $this->assertSame(3, $item->fresh()->quantity);
        $this->assertSame('9.99', $item->fresh()->total);
        $this->assertSame('9.99', $item->cart->fresh()->subtotal);
    }

    #[Test]
    public function an_item_can_be_removed(): void
    {
        $product = Product::factory()->priced('3.00')->create();
        $this->addToCart($product, 2);
        $item = CartItem::sole();

        $this->delete(route('shop.cart.items.destroy', $item))->assertRedirect();

        $this->assertSame(0, $this->cartItemCount());
        $this->assertSame('0.00', $item->cart->fresh()->subtotal);
    }

    #[Test]
    public function nobody_can_change_someone_elses_cart(): void
    {
        $product = Product::factory()->priced('3.00')->create();
        $owner = User::factory()->create();
        $theirs = $this->cartWith($product, 1, $owner)->items->first();

        $this->patch(route('shop.cart.items.update', $theirs), ['quantity' => 5])->assertNotFound();
        $this->delete(route('shop.cart.items.destroy', $theirs))->assertNotFound();

        $this->actingAs(User::factory()->create())
            ->patch(route('shop.cart.items.update', $theirs), ['quantity' => 5])
            ->assertNotFound();

        $this->assertSame(1, $theirs->fresh()->quantity);
    }

    #[Test]
    public function a_cart_that_became_an_order_is_not_reused(): void
    {
        $product = Product::factory()->priced('3.00')->create();
        $cart = $this->cartWith($product);
        $cart->update(['status' => 'converted']);

        $this->get(route('shop.cart'))
            ->assertInertia(fn (Assert $page) => $page->where('cart', null));
    }
}
