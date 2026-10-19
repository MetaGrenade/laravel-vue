<?php

namespace Tests\Feature\Tebex;

use App\Models\CartItem;
use App\Models\Order;
use App\Models\SystemSetting;
use App\Models\TebexPackage;
use App\Models\User;
use App\Payments\Data\CheckoutContext;
use App\Payments\Exceptions\PaymentException;
use App\Payments\PaymentManager;
use App\Payments\Tebex\TebexCatalogue;
use App\Payments\Tebex\TebexStoreProfile;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithCommerce;
use Tests\Support\InteractsWithTebex;
use Tests\TestCase;

/**
 * The shop sells only for a Tebex store whose platform needs no sign-in ("Universal (No Auth)"), and says so for any other.
 */
class TebexPlatformTest extends TestCase
{
    use InteractsWithCommerce;
    use InteractsWithTebex;
    use RefreshDatabase;

    private const MINECRAFT = 'Minecraft: Java Edition';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerce();
        $this->setUpTebex();
        $this->sellThroughTebex();
        $this->seed(RolePermissionSeeder::class);
    }

    private function package(): TebexPackage
    {
        return TebexPackage::factory()->priced('10.00')->create(['ident' => 'vip-rank', 'name' => 'VIP']);
    }

    private function sync(): void
    {
        app(TebexCatalogue::class)->sync();
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('admin');
    }

    // --- Remembering the store ---------------------------------------------------------------------------------

    #[Test]
    public function a_synchronisation_remembers_the_store_and_what_it_is_set_up_for(): void
    {
        $this->fakeTebexBaskets();

        $this->sync();

        $stored = SystemSetting::get(TebexStoreProfile::SETTING);
        $this->assertSame('Example Store', $stored['name']);
        $this->assertSame('USD', $stored['currency']);
        $this->assertSame('Universal (No Auth)', $stored['platform']);
        $this->assertNotEmpty($stored['checked_at']);
        $this->assertStringNotContainsString('private-key', (string) json_encode($stored), 'nothing secret is kept');
        $this->assertTrue(app(TebexStoreProfile::class)->isSupported());
        $this->assertNull(app(TebexStoreProfile::class)->unsupportedReason());
    }

    #[Test]
    public function testing_the_connection_remembers_it_too_and_says_what_it_found(): void
    {
        $this->fakeTebexBaskets($this->tebexStoreOn(self::MINECRAFT));

        $this->actingAs($this->admin())->post(route('acp.commerce.tebex.test'))
            ->assertSessionHas('tebex_test', fn (array $test) => $test['ok'] && $test['platform'] === self::MINECRAFT && $test['supported'] === false);

        $this->assertSame(self::MINECRAFT, SystemSetting::get(TebexStoreProfile::SETTING)['platform']);
    }

    #[Test]
    public function the_page_says_the_shop_cannot_sell_for_such_a_store_and_what_it_can_sell_for(): void
    {
        $this->fakeTebexBaskets($this->tebexStoreOn(self::MINECRAFT));
        $this->sync();

        $this->actingAs($this->admin())->get(route('acp.commerce.tebex.index'))->assertInertia(fn (Assert $page) => $page
            ->where('store.supported', false)
            ->where('store.platform', self::MINECRAFT)
            ->where('store.supported_platform', 'Universal (No Auth)')
            ->where('store.reason', fn (string $reason) => str_contains($reason, self::MINECRAFT) && str_contains($reason, 'Universal (No Auth)')));
    }

    #[Test]
    public function before_the_store_has_been_looked_at_nothing_is_refused_and_the_page_has_no_store(): void
    {
        $this->actingAs($this->admin())->get(route('acp.commerce.tebex.index'))->assertInertia(fn (Assert $page) => $page->where('store', null));

        $this->assertTrue(app(TebexStoreProfile::class)->isSupported());
        $this->assertNull(app(TebexStoreProfile::class)->platform());
    }

    // --- What is refused ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_package_of_such_a_store_cannot_be_bought_and_says_why(): void
    {
        $this->fakeTebexBaskets($this->tebexStoreOn(self::MINECRAFT));
        $this->sync();
        // (Made after the synchronisation, which would otherwise retire a package its fixture does not list.)
        $package = $this->package();
        $reason = app(TebexStoreProfile::class)->unsupportedReason();

        $this->get(route('shop.packages'))->assertInertia(fn (Assert $page) => $page
            ->where('packages.data.0.can_buy', false)
            ->where('packages.data.0.reason', $reason));
        $this->get(route('shop.packages.show', $package->tebex_id))->assertInertia(fn (Assert $page) => $page->where('package.can_buy', false)->where('package.reason', $reason));

        $this->post(route('shop.cart.packages.store'), ['package_id' => $package->tebex_id, 'quantity' => 1])->assertRedirect()->assertSessionHas('error', $reason);
        $this->assertSame(0, CartItem::query()->count());
    }

    #[Test]
    public function a_cart_filled_before_the_store_was_known_cannot_be_checked_out(): void
    {
        // The store was not known to be one the shop cannot sell for when the cart was filled.
        $tebex = $this->fakeTebexBaskets($this->tebexStoreOn(self::MINECRAFT));
        $package = $this->package();
        $this->post(route('shop.cart.packages.store'), ['package_id' => $package->tebex_id, 'quantity' => 1])->assertSessionHas('success');

        $this->sync();
        // (The fixture does not list the package, so the synchronisation retires it; bring it back for the checkout.)
        $package->update(['removed_at' => null]);

        $this->get(route('shop.cart'))->assertInertia(fn (Assert $page) => $page->where('checkoutAvailable', false));
        $this->post(route('shop.checkout.store'), ['email' => 'buyer@example.com', 'token' => (string) Str::uuid()])
            ->assertRedirect(route('shop.cart'))
            ->assertSessionHas('error', 'Checkout is not available right now. Please try again later.');

        $this->assertSame(0, Order::query()->count());
        $this->assertCount(0, $tebex->calls('create'), 'no basket was made at Tebex');
    }

    #[Test]
    public function the_provider_is_not_ready_and_makes_no_basket_for_such_a_store(): void
    {
        $order = Order::factory()->create(['payment_provider' => 'tebex']);
        $order->items()->create(['tebex_package_id' => $this->package()->id, 'quantity' => 1, 'unit_price' => '10.00', 'subtotal' => '10.00']);
        $tebex = $this->fakeTebexBaskets($this->tebexStoreOn(self::MINECRAFT));
        $this->sync();
        $provider = app(PaymentManager::class)->provider('tebex');

        $this->assertFalse($provider->isConfigured());
        $this->assertSame(['key' => 'tebex', 'label' => 'Tebex', 'configured' => false], collect(app(PaymentManager::class)->options())->firstWhere('key', 'tebex'));

        try {
            $provider->startCheckout($order, new CheckoutContext('https://shop.test/done', 'https://shop.test/cart', now()->addHour()));
            $this->fail('A basket was made for a store the shop cannot sell for.');
        } catch (PaymentException $exception) {
            $this->assertStringContainsString(self::MINECRAFT, $exception->getMessage());
        }

        $this->assertCount(0, $tebex->calls('create'));
    }

    #[Test]
    public function a_platform_the_shop_has_never_heard_of_is_refused_too(): void
    {
        $this->fakeTebexBaskets($this->tebexStoreOn('A Game From Next Year'));

        $this->sync();

        $this->assertFalse(app(TebexStoreProfile::class)->isSupported());
        $this->assertStringContainsString('A Game From Next Year', (string) app(TebexStoreProfile::class)->unsupportedReason());
    }

    // --- What is allowed -----------------------------------------------------------------------------------------------

    #[Test]
    public function the_platform_may_be_written_with_other_spacing_and_case(): void
    {
        $this->fakeTebexBaskets($this->tebexStoreOn('  universal (NO auth) '));

        $this->sync();

        $this->assertTrue(app(TebexStoreProfile::class)->isSupported());
    }

    #[Test]
    public function a_store_that_does_not_say_what_it_is_set_up_for_is_not_refused(): void
    {
        $this->fakeTebexBaskets($this->tebexStoreOn(null));

        $this->sync();

        $this->assertNull(app(TebexStoreProfile::class)->platform());
        $this->assertTrue(app(TebexStoreProfile::class)->isSupported(), 'unknown is not the same as unsupported');
    }

    #[Test]
    public function a_store_that_is_universal_is_sold_for_end_to_end(): void
    {
        $tebex = $this->fakeTebexBaskets();
        $this->sync();
        $package = $this->package();
        $this->post(route('shop.cart.packages.store'), ['package_id' => $package->tebex_id, 'quantity' => 1])->assertSessionHas('success');

        $this->post(route('shop.checkout.store'), ['email' => 'buyer@example.com', 'token' => (string) Str::uuid()])->assertRedirect();

        $this->assertSame(1, Order::query()->count());
        $this->assertCount(1, $tebex->calls('create'));
    }

    // --- Changes of mind ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_next_synchronisation_lifts_the_refusal_when_the_store_changes_platform(): void
    {
        $this->fakeTebexBaskets($this->tebexStoreOn(self::MINECRAFT));
        $this->sync();
        $this->assertFalse(app(TebexStoreProfile::class)->isSupported());

        $this->fakeTebexBaskets();
        $this->sync();

        $this->assertTrue(app(TebexStoreProfile::class)->isSupported());
        $this->assertSame('Universal (No Auth)', SystemSetting::get(TebexStoreProfile::SETTING)['platform']);
    }

    #[Test]
    public function a_synchronisation_that_fails_does_not_make_the_shop_forget_what_it_knew(): void
    {
        $this->fakeTebexBaskets($this->tebexStoreOn(self::MINECRAFT));
        $this->sync();

        $this->fakeTebexHttp(['headless.tebex.io/*' => Http::response([], 503)]);
        $this->sync();

        $this->assertFalse(app(TebexStoreProfile::class)->isSupported());
        $this->assertSame(self::MINECRAFT, SystemSetting::get(TebexStoreProfile::SETTING)['platform']);
    }

    #[Test]
    public function what_was_remembered_is_read_back_by_a_fresh_request(): void
    {
        $this->fakeTebexBaskets($this->tebexStoreOn(self::MINECRAFT));
        $this->sync();

        // As the next request would find it: nothing in memory, only the database.
        $this->app->forgetInstance(TebexStoreProfile::class);

        $this->assertSame(self::MINECRAFT, app(TebexStoreProfile::class)->platform());
        $this->assertFalse(app(TebexStoreProfile::class)->isSupported());
    }
}
