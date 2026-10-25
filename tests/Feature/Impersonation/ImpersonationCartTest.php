<?php

namespace Tests\Feature\Impersonation;

use App\Models\Cart;
use App\Models\Product;
use App\Models\User;
use App\Support\Commerce\CartManager;
use App\Support\Impersonation\Impersonation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\Support\InteractsWithCommerce;
use Tests\TestCase;

/**
 * A view as a customer shows the customer's cart, and not one tied to the staff member's browser session (docs/impersonation.md).
 *
 * The browser session is the staff member's while the person signed in is the customer, and a cart is found by either.
 */
class ImpersonationCartTest extends TestCase
{
    use InteractsWithCommerce;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00'));

        // The test client's browser session: the staff member's, in these tests.
        $this->setUpCommerce();
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['nickname' => 'Ada Admin', 'email' => 'ada@example.com']);
        $admin->assignRole(Role::findOrCreate('admin'));

        return $admin;
    }

    private function viewing(User $admin, User $customer): static
    {
        $this->actingAs($admin);

        return $this->withSession([Impersonation::SESSION_KEY => [
            'admin_id' => $admin->id,
            'user_id' => $customer->id,
            'reason' => 'Ticket 4411: a cart that will not check out',
            'started_at' => now()->getTimestamp(),
            'expires_at' => now()->addMinutes(30)->getTimestamp(),
            'views' => 0,
        ]]);
    }

    #[Test]
    public function the_customers_cart_is_shown_and_not_one_tied_to_the_staff_members_browser(): void
    {
        $customer = User::factory()->create(['nickname' => 'Jane Customer', 'email' => 'jane@example.com']);
        $mugs = Product::factory()->priced('8.00')->create(['name' => 'Mug']);
        $hoodies = Product::factory()->priced('40.00')->create(['name' => 'Hoodie']);

        $customersCart = $this->cartWith($mugs, 2, $customer);
        // Made after the customer's, so it is the newest: the one that wins when a cart is found by the session as well.
        $staffsCart = $this->cartWith($hoodies, 1);

        $this->assertGreaterThan($customersCart->id, $staffsCart->id);

        $this->viewing($this->admin(), $customer)->get(route('shop.cart'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('commerce/Cart')
            ->where('cart.id', $customersCart->id)
            ->where('cart.count', 2)
            ->where('cart.items.0.name', 'Mug'));

        $this->assertNull($staffsCart->fresh()->user_id, 'and the staff members cart is not handed to the customer');
    }

    #[Test]
    public function the_cart_in_the_bar_of_every_page_is_the_customers_too(): void
    {
        $customer = User::factory()->create(['nickname' => 'Jane Customer', 'email' => 'jane@example.com']);

        $customersCart = $this->cartWith(Product::factory()->priced('8.00')->create(), 3, $customer);
        $this->cartWith(Product::factory()->priced('40.00')->create(), 1);

        $this->viewing($this->admin(), $customer)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('cart.id', $customersCart->id)
            ->where('cart.count', 3));
    }

    #[Test]
    public function a_customer_with_no_cart_has_none_whatever_the_staff_members_browser_holds(): void
    {
        $customer = User::factory()->create(['nickname' => 'Jane Customer', 'email' => 'jane@example.com']);
        $somebodyElse = User::factory()->create(['nickname' => 'Somebody Else', 'email' => 'else@example.com']);
        $this->cartWith(Product::factory()->priced('40.00')->create(), 1);
        $this->cartWith(Product::factory()->priced('9.00')->create(), 1, $somebodyElse);

        $this->viewing($this->admin(), $customer)->get(route('shop.cart'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('cart', null));

        $this->assertSame(0, Cart::query()->where('user_id', $customer->id)->count(), 'and nothing is made for them');
    }

    #[Test]
    public function without_a_view_a_cart_tied_to_the_browser_is_still_found_as_before(): void
    {
        // The control: the customer, in their own browser with a cart from before they signed in, is given that cart (so the tests
        // above are about the view, not about carts that are never found by the session).
        $customer = User::factory()->create(['nickname' => 'Jane Customer', 'email' => 'jane@example.com']);

        $this->cartWith(Product::factory()->priced('8.00')->create(), 2, $customer);
        $browsersCart = $this->cartWith(Product::factory()->priced('40.00')->create(), 1);

        $this->actingAs($customer)->get(route('shop.cart'))->assertInertia(fn (Assert $page) => $page->where('cart.id', $browsersCart->id));
    }

    #[Test]
    public function a_request_made_as_nobody_finds_no_cart(): void
    {
        // A search with no condition would find anybody's cart, so there must be none to find when there is no person and no session.
        $this->cartWith(Product::factory()->priced('40.00')->create(), 1);

        $request = Request::create('/cart');
        $request->setLaravelSession(app('session.store'));
        $request->attributes->set(Impersonation::ATTRIBUTE, true);

        $this->assertNull(CartManager::forRequest($request));
        $this->assertNull(CartManager::forRequest($request, create: true));
    }
}
