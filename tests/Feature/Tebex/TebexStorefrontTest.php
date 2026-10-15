<?php

namespace Tests\Feature\Tebex;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\GiftCard;
use App\Models\Product;
use App\Models\TebexCategory;
use App\Models\TebexPackage;
use App\Models\User;
use App\Support\Commerce\CartManager;
use App\Support\Commerce\GuestCarryOver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithCommerce;
use Tests\Support\InteractsWithTebex;
use Tests\TestCase;

/**
 * A store that sells through Tebex: browsing its packages and putting them in the cart.
 */
class TebexStorefrontTest extends TestCase
{
    use InteractsWithCommerce;
    use InteractsWithTebex;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerce();
        $this->setUpTebex();
        $this->sellThroughTebex();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function package(array $attributes = []): TebexPackage
    {
        return TebexPackage::factory()->priced('10.00')->create($attributes);
    }

    private function addPackage(TebexPackage $package, int $quantity = 1)
    {
        return $this->post(route('shop.cart.packages.store'), ['package_id' => $package->tebex_id, 'quantity' => $quantity]);
    }

    // --- Where the pages are --------------------------------------------------------------------------

    #[Test]
    public function the_pages_are_there_only_while_tebex_is_the_stores_provider(): void
    {
        $package = $this->package();
        config(['commerce.provider' => 'stripe']);

        $this->get(route('shop.packages'))->assertNotFound();
        $this->get(route('shop.packages.show', $package->tebex_id))->assertNotFound();
        $this->addPackage($package)->assertNotFound();
        $this->assertSame(0, CartItem::query()->count());
    }

    #[Test]
    public function the_shop_window_sends_a_tebex_store_to_its_packages_and_leaves_any_other_alone(): void
    {
        $this->get(route('shop.index'))->assertRedirect(route('shop.packages'));

        config(['commerce.provider' => 'stripe']);

        $this->get(route('shop.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('commerce/Catalog'));
    }

    #[Test]
    public function a_tebex_store_does_not_sell_its_own_products(): void
    {
        $product = Product::factory()->priced('5.00')->stocked(5)->create();

        $this->post(route('shop.cart.items.store'), ['product_id' => $product->id, 'quantity' => 1])
            ->assertRedirect()->assertSessionHas('error', 'This shop sells packages, not products.');
        $this->assertSame(0, CartItem::query()->count());
    }

    // --- The catalogue ---------------------------------------------------------------------------------

    #[Test]
    public function it_lists_what_can_be_bought_from_the_local_copy(): void
    {
        $category = TebexCategory::factory()->create(['name' => 'Ranks']);
        $this->package(['name' => 'VIP', 'tebex_category_id' => $category->id, 'description' => '<p>Perks <strong>galore</strong></p>']);
        $this->package(['name' => 'Gone', 'removed_at' => now()]);
        $this->package(['name' => 'Over', 'expires_at' => now()->subDay()]);
        $this->package(['name' => 'Soon', 'expires_at' => now()->addDay()]);
        $this->package(['name' => 'Choosy', 'variables' => [['identifier' => 'server']]]);

        $this->get(route('shop.packages'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('commerce/TebexCatalog')
                ->has('packages.data', 3)
                ->where('packages.data.0.name', 'VIP')
                ->where('packages.data.0.category', 'Ranks')
                ->where('packages.data.0.price', '10.00')
                ->where('packages.data.0.currency', 'USD')
                ->where('packages.data.0.excerpt', 'Perks galore')
                ->where('packages.data.0.can_buy', true)
                ->where('packages.data.0.reason', null)
                ->where('packages.data.0.recurring', false)
                ->where('packages.last_page', 1));
    }

    #[Test]
    public function a_package_that_cannot_be_bought_here_is_shown_with_the_reason(): void
    {
        $this->package(['name' => 'Choosy', 'variables' => [['identifier' => 'server']]]);
        $this->package(['name' => 'Free', 'base_price' => '0.00', 'total_price' => '0.00']);

        $this->get(route('shop.packages'))->assertInertia(fn (Assert $page) => $page
            ->where('packages.data.0.can_buy', false)
            ->where('packages.data.0.reason', 'Needs choices the shop cannot collect yet, so it cannot be bought here.')
            ->where('packages.data.1.can_buy', false)
            ->where('packages.data.1.reason', 'Is free, which the shop cannot give out through Tebex yet.'));
    }

    #[Test]
    public function the_list_can_be_searched_filtered_by_category_and_is_paged(): void
    {
        $ranks = TebexCategory::factory()->create(['name' => 'Ranks']);
        $cosmetics = TebexCategory::factory()->create(['name' => 'Cosmetics']);
        $this->package(['name' => 'VIP rank', 'tebex_category_id' => $ranks->id]);
        $this->package(['name' => 'Pet cat', 'tebex_category_id' => $cosmetics->id, 'description' => 'A very cute kitten']);

        $this->get(route('shop.packages', ['search' => 'vip']))->assertInertia(fn (Assert $page) => $page->has('packages.data', 1)->where('packages.data.0.name', 'VIP rank'));
        $this->get(route('shop.packages', ['search' => 'kitten']))->assertInertia(fn (Assert $page) => $page->has('packages.data', 1)->where('packages.data.0.name', 'Pet cat'));
        $this->get(route('shop.packages', ['category' => $cosmetics->id]))->assertInertia(fn (Assert $page) => $page
            ->has('packages.data', 1)
            ->where('filters.category', $cosmetics->id)
            ->where('categories', [['id' => $ranks->id, 'name' => 'Ranks', 'count' => 1], ['id' => $cosmetics->id, 'name' => 'Cosmetics', 'count' => 1]]));

        TebexPackage::factory()->count(30)->create(['tebex_category_id' => $ranks->id]);

        $this->get(route('shop.packages'))->assertInertia(fn (Assert $page) => $page
            ->has('packages.data', 24)->where('packages.total', 32)->where('packages.last_page', 2)->where('packages.prev_page_url', null)->whereNot('packages.next_page_url', null));
    }

    #[Test]
    public function a_category_with_nothing_for_sale_is_not_offered(): void
    {
        $empty = TebexCategory::factory()->create(['name' => 'Empty']);
        $this->package(['tebex_category_id' => $empty->id, 'removed_at' => now()]);

        $this->get(route('shop.packages'))->assertInertia(fn (Assert $page) => $page->where('categories', []));
    }

    // --- A package's page --------------------------------------------------------------------------------

    #[Test]
    public function a_package_has_a_page_with_its_description_cleaned(): void
    {
        $package = $this->package([
            'name' => 'VIP',
            'description' => '<p>Perks</p><script>alert(1)</script><img src="https://cdn.example.test/a.png" onerror="alert(2)"><a href="javascript:alert(3)">x</a>',
            'image_url' => 'https://cdn.example.test/vip.png',
            'media' => [
                ['type' => 'image', 'url' => 'https://cdn.example.test/two.png'],
                ['type' => 'image', 'url' => 'javascript:alert(4)'],
                ['type' => 'video', 'url' => 'https://cdn.example.test/clip.mp4'],
            ],
        ]);

        $response = $this->get(route('shop.packages.show', $package->tebex_id));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('commerce/TebexPackage')
            ->where('package.name', 'VIP')
            ->where('package.pictures', ['https://cdn.example.test/vip.png', 'https://cdn.example.test/two.png'])
            ->where('package.max_quantity', 20)
            ->where('package.can_buy', true));

        $html = $response->viewData('page')['props']['package']['description_html'];
        $this->assertStringContainsString('<p>Perks</p>', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    #[Test]
    public function a_picture_that_is_not_a_web_address_is_not_used(): void
    {
        $package = $this->package(['image_url' => 'javascript:alert(1)']);

        $this->get(route('shop.packages.show', $package->tebex_id))->assertInertia(fn (Assert $page) => $page->where('package.image', null)->where('package.pictures', []));
    }

    #[Test]
    public function a_package_tebex_no_longer_lists_or_that_has_expired_has_no_page(): void
    {
        $this->get(route('shop.packages.show', $this->package(['removed_at' => now()])->tebex_id))->assertNotFound();
        $this->get(route('shop.packages.show', $this->package(['expires_at' => now()->subMinute()])->tebex_id))->assertNotFound();
        $this->get(route('shop.packages.show', 999999))->assertNotFound();
    }

    #[Test]
    public function a_package_that_is_not_sold_in_quantities_says_so(): void
    {
        $single = $this->package(['disable_quantity' => true]);
        $limited = $this->package(['user_limit' => 3]);

        $this->get(route('shop.packages.show', $single->tebex_id))->assertInertia(fn (Assert $page) => $page->where('package.max_quantity', 1));
        $this->get(route('shop.packages.show', $limited->tebex_id))->assertInertia(fn (Assert $page) => $page->where('package.max_quantity', 3));
    }

    // --- The cart -------------------------------------------------------------------------------------------

    #[Test]
    public function a_package_goes_in_the_cart_at_the_price_tebex_quoted(): void
    {
        $package = $this->package(['name' => 'VIP', 'base_price' => '9.99', 'total_price' => '11.99', 'sales_tax' => '2.00']);

        $this->addPackage($package, 2)->assertRedirect()->assertSessionHas('success', 'Added to your cart.');

        $item = CartItem::query()->sole();
        $this->assertSame($package->id, $item->tebex_package_id);
        $this->assertNull($item->product_id);
        $this->assertSame(2, $item->quantity);
        $this->assertSame('9.99', $item->unit_price);
        $this->assertSame('19.98', $item->total, 'the price before tax: Tebex adds its own tax');
        $this->assertSame('VIP', $item->snapshot['package']['name']);
        $this->assertSame('19.98', $item->cart->subtotal);
        $this->assertSame(CartManager::PACKAGES, CartManager::kind($item->cart));
    }

    #[Test]
    public function adding_it_again_adds_to_the_line_up_to_the_limit(): void
    {
        $package = $this->package();

        $this->addPackage($package, 15);
        $this->addPackage($package, 15);

        $this->assertSame(20, CartItem::query()->sole()->quantity);
    }

    #[Test]
    public function a_package_that_is_not_sold_in_quantities_is_held_to_one_and_a_customer_limit_is_kept(): void
    {
        $single = $this->package(['disable_quantity' => true]);
        $limited = $this->package(['user_limit' => 3]);

        $this->addPackage($single, 5);
        $this->addPackage($limited, 9);

        $this->assertSame(1, CartItem::query()->where('tebex_package_id', $single->id)->sole()->quantity);
        $this->assertSame(3, CartItem::query()->where('tebex_package_id', $limited->id)->sole()->quantity);
    }

    #[Test]
    public function what_cannot_be_bought_cannot_be_put_in_the_cart(): void
    {
        foreach ([
            [['removed_at' => now()], 'This package is not available.'],
            [['expires_at' => now()->subMinute()], 'This package is not available.'],
            [['variables' => [['identifier' => 'server']]], 'needs choices the shop cannot collect yet'],
            [['base_price' => '0.00', 'total_price' => '0.00'], 'is free'],
            [['ident' => null], "can't be purchased right now"],
        ] as [$attributes, $message]) {
            $package = $this->package($attributes);

            $this->addPackage($package)->assertRedirect()->assertSessionHas('error', fn (string $error) => str_contains($error, $message));
        }

        $this->post(route('shop.cart.packages.store'), ['package_id' => 424242, 'quantity' => 1])->assertSessionHas('error', 'This package is not available.');
        $this->assertSame(0, CartItem::query()->count());
    }

    #[Test]
    public function a_quantity_outside_the_shops_limits_is_refused(): void
    {
        $package = $this->package();

        $this->addPackage($package, 0)->assertSessionHasErrors('quantity');
        $this->addPackage($package, 21)->assertSessionHasErrors('quantity');
        $this->assertSame(0, CartItem::query()->count());
    }

    #[Test]
    public function packages_and_products_do_not_share_a_cart(): void
    {
        $product = Product::factory()->priced('5.00')->stocked(5)->create();
        config(['commerce.provider' => 'stripe']);
        $this->cartWith($product, 1);
        config(['commerce.provider' => 'tebex']);

        $this->addPackage($this->package())->assertSessionHas('error', fn (string $error) => str_contains($error, 'products in it'));
        $this->assertSame(1, CartItem::query()->count());
    }

    #[Test]
    public function the_packages_in_one_cart_are_in_one_currency(): void
    {
        $dollars = $this->package(['currency' => 'USD']);
        $euros = $this->package(['currency' => 'EUR']);

        $this->addPackage($dollars)->assertSessionHas('success');
        $this->addPackage($euros)->assertSessionHas('error', fn (string $error) => str_contains($error, 'sold in EUR'));

        $this->assertSame(1, CartItem::query()->count());
    }

    #[Test]
    public function a_cart_of_packages_is_in_their_currency_and_goes_back_to_the_shops_when_it_is_emptied(): void
    {
        $euros = $this->package(['currency' => 'EUR', 'base_price' => '8.00', 'total_price' => '8.00']);

        $this->addPackage($euros);
        $cart = CartItem::query()->sole()->cart;
        $this->assertSame('EUR', $cart->currency);

        CartManager::removeItem(CartItem::query()->sole());

        $this->assertSame('USD', $cart->fresh()->currency);
    }

    #[Test]
    public function the_cart_page_says_tebex_does_the_tax_and_the_codes_and_links_to_the_package(): void
    {
        $package = $this->package(['name' => 'VIP', 'image_url' => 'https://cdn.example.test/vip.png']);
        $this->addPackage($package);

        $this->get(route('shop.cart'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('commerce/Cart')
            ->where('tebex', true)
            ->where('coupon', null)
            ->where('giftCard', null)
            ->where('giftCardsEnabled', false)
            ->where('cart.currency', 'USD')
            ->where('cart.items.0.name', 'VIP')
            ->where('cart.items.0.href', route('shop.packages.show', $package->tebex_id))
            ->where('cart.items.0.image', 'https://cdn.example.test/vip.png')
            ->where('cart.items.0.slug', null)
            ->where('checkoutAvailable', true));
    }

    #[Test]
    public function a_package_that_has_since_been_removed_has_no_link_in_the_cart(): void
    {
        $package = $this->package(['name' => 'VIP']);
        $this->addPackage($package);
        $package->update(['removed_at' => now()]);

        $this->get(route('shop.cart'))->assertInertia(fn (Assert $page) => $page->where('cart.items.0.href', null)->where('cart.items.0.name', 'VIP'));
    }

    #[Test]
    public function a_quantity_can_be_changed_and_a_line_removed_like_any_other(): void
    {
        $this->addPackage($this->package());
        $item = CartItem::query()->sole();

        $this->patch(route('shop.cart.items.update', $item), ['quantity' => 4])->assertSessionHas('success');
        $this->assertSame('40.00', $item->fresh()->total);
        $this->assertSame('40.00', $item->cart->fresh()->subtotal);

        $this->delete(route('shop.cart.items.destroy', $item))->assertSessionHas('success');
        $this->assertSame(0, CartItem::query()->count());
    }

    #[Test]
    public function the_shops_own_codes_and_gift_cards_are_not_used_with_packages(): void
    {
        $this->addPackage($this->package());
        Coupon::factory()->create(['code' => 'SAVE10']);
        GiftCard::factory()->code('ABCD-EFGH-JKLM-NPQR')->worth('20.00')->withHistory()->create();

        $this->post(route('shop.cart.coupon.store'), ['code' => 'SAVE10'])->assertSessionHasErrors(['code' => "Tebex takes discount codes when you pay, so the shop's own codes are not used with packages."]);
        $this->post(route('shop.cart.gift-card.store'), ['gift_card' => 'ABCD-EFGH-JKLM-NPQR'])->assertSessionHasErrors('gift_card');

        $cart = CartItem::query()->sole()->cart->fresh();
        $this->assertNull($cart->coupon_id);
        $this->assertNull($cart->gift_card_id);
    }

    // --- Signing in ---------------------------------------------------------------------------------------

    #[Test]
    public function a_guests_packages_join_the_cart_of_the_account_they_sign_in_to(): void
    {
        $package = $this->package();
        $other = $this->package();
        $user = User::factory()->create();

        $this->addPackage($package, 2);
        $this->addPackage($other, 1);
        $mine = Cart::query()->create(['user_id' => $user->id, 'session_id' => Str::random(40), 'status' => CartManager::OPEN, 'currency' => 'USD']);
        CartManager::addPackage($mine, $package, 3);

        $merged = app(GuestCarryOver::class)->mergeCart($user, $this->guestSessionId);

        $this->assertSame($mine->id, $merged->id);
        $lines = $merged->items()->orderBy('tebex_package_id')->get()->mapWithKeys(fn (CartItem $item) => [$item->tebex_package_id => $item->quantity]);
        $this->assertEquals([$package->id => 5, $other->id => 1], $lines->all(), 'the same package adds up; another stays its own line');
    }
}
