<?php

namespace Tests\Feature\Performance;

use App\Models\Cart;
use App\Models\Product;
use App\Models\TebexPackage;
use App\Models\User;
use App\Support\Commerce\CartManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CountsQueries;
use Tests\Support\InteractsWithCommerce;
use Tests\Support\InteractsWithTebex;
use Tests\TestCase;

/**
 * What the pages of a shopper's way through the shop cost in queries (docs/query-budgets.md); the pages a visitor reads are in
 * {@see QueryBudgetTest}. These need a payment provider, so they have their own set-up.
 */
class QueryBudgetShopTest extends TestCase
{
    use CountsQueries;
    use InteractsWithCommerce;
    use InteractsWithTebex;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerce();
    }

    #[Test]
    public function the_checkout_page(): void
    {
        $user = User::factory()->create();
        $cart = Cart::create(['user_id' => $user->id, 'currency' => 'USD']);

        $this->assertQueryCost(
            'the checkout page',
            function () use ($cart) {
                $product = Product::factory()->priced('10.00')->stocked(10)->create();
                CartManager::addItem($cart, $product, null, $product->prices()->firstOrFail(), 1);
            },
            fn () => $this->actingAs($user)->get(route('shop.checkout'))->assertOk(),
            35,
        );
    }

    #[Test]
    public function the_package_list_of_a_store_that_sells_through_tebex(): void
    {
        $this->setUpTebex();
        $this->sellThroughTebex();

        $this->assertQueryCost(
            'the package list',
            fn () => TebexPackage::factory()->priced('10.00')->create(),
            fn () => $this->get(route('shop.packages'))->assertOk(),
            19,
        );
    }

    #[Test]
    public function a_package_of_a_store_that_sells_through_tebex(): void
    {
        $this->setUpTebex();
        $this->sellThroughTebex();
        $package = TebexPackage::factory()->priced('10.00')->create();

        $this->assertQueryCost(
            'a package',
            fn () => TebexPackage::factory()->priced('10.00')->create(['tebex_category_id' => $package->tebex_category_id]),
            fn () => $this->get(route('shop.packages.show', $package->tebex_id))->assertOk(),
            17,
        );
    }

    #[Test]
    public function the_cart_of_a_store_that_sells_through_tebex(): void
    {
        $this->setUpTebex();
        $this->sellThroughTebex();
        $user = User::factory()->create();
        $cart = Cart::create(['user_id' => $user->id, 'currency' => 'USD']);

        $this->assertQueryCost(
            'the cart of packages',
            fn () => CartManager::addPackage($cart, TebexPackage::factory()->priced('10.00')->create(), 1),
            fn () => $this->actingAs($user)->get(route('shop.cart'))->assertOk(),
            25,
        );
    }
}
