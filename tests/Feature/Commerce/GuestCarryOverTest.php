<?php

namespace Tests\Feature\Commerce;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\Commerce\CartManager;
use App\Support\Commerce\GuestCarryOver;
use App\Support\Security\TwoFactorAuthenticator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithCommerce;
use Tests\TestCase;

/**
 * What a shopper did as a guest follows them into their account: the cart when they sign in, and the orders
 * placed with their email once they have proved it is theirs.
 */
class GuestCarryOverTest extends TestCase
{
    use InteractsWithCommerce;
    use RefreshDatabase;

    private GuestCarryOver $carryOver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerce();
        $this->carryOver = app(GuestCarryOver::class);
    }

    private function product(string $price = '10.00'): Product
    {
        return Product::factory()->priced($price)->stocked(50)->create();
    }

    /**
     * @return array<string, int> Quantity of each product in the cart, by product id and variant id, in key order
     *                            (a database returns rows in whatever order it likes, and PostgreSQL moves an updated one).
     */
    private function lines(Cart $cart): array
    {
        $lines = $cart->items()->get()->mapWithKeys(fn ($item) => [$item->product_id.'/'.($item->product_variant_id ?? '-') => $item->quantity])->all();

        ksort($lines);

        return $lines;
    }

    private function userWithTwoFactor(): User
    {
        $user = User::factory()->create(['email' => 'ada@example.test']);
        $user->forceFill([
            'two_factor_secret' => TwoFactorAuthenticator::encryptSecret(TwoFactorAuthenticator::generateSecret()),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => TwoFactorAuthenticator::encryptRecoveryCodes(['TEST-RECOVERY-CODE']),
        ])->save();

        return $user;
    }

    private function guestOrder(string $email, array $attributes = []): Order
    {
        return Order::factory()->paid()->create(['customer_email' => $email, 'grand_total' => '10.00', ...$attributes]);
    }

    // --- The cart --------------------------------------------------------------------------------

    #[Test]
    public function a_guest_cart_becomes_the_accounts_cart_when_the_account_has_none(): void
    {
        $user = User::factory()->create();
        $product = $this->product();
        $guest = $this->cartWith($product, 2);

        $cart = $this->carryOver->mergeCart($user, $this->guestSessionId);

        $this->assertSame($guest->id, $cart->id);
        $this->assertSame($user->id, $cart->user_id);
        $this->assertSame([$product->id.'/-' => 2], $this->lines($cart));
        $this->assertSame(1, Cart::query()->count());
    }

    #[Test]
    public function a_guest_cart_is_merged_into_the_accounts_existing_cart(): void
    {
        $user = User::factory()->create();
        [$a, $b, $c] = [$this->product('10.00'), $this->product('5.00'), $this->product('2.50')];
        $mine = $this->cartWith($a, 2, $user);
        CartManager::addItem($mine, $b, null, $b->prices()->firstOrFail(), 1);
        $guest = $this->cartWith($a, 3);
        CartManager::addItem($guest, $c, null, $c->prices()->firstOrFail(), 4);

        $cart = $this->carryOver->mergeCart($user, $this->guestSessionId);

        $this->assertSame($mine->id, $cart->id, 'the account keeps its own cart');
        $expected = [$a->id.'/-' => 5, $b->id.'/-' => 1, $c->id.'/-' => 4];
        ksort($expected);
        $this->assertSame($expected, $this->lines($cart), 'the same line is added to, the rest move over');
        $this->assertSame('65.00', $cart->subtotal, '5 x 10.00 + 1 x 5.00 + 4 x 2.50, worked out again');
        $this->assertNull(Cart::query()->find($guest->id), 'there is no second cart to choose between');
        $this->assertSame(1, Cart::query()->where('status', CartManager::OPEN)->count());
    }

    #[Test]
    public function a_line_never_grows_past_the_most_one_line_may_hold(): void
    {
        config(['commerce.checkout.max_quantity' => 20]);
        $user = User::factory()->create();
        $product = $this->product();
        $this->cartWith($product, 18, $user);
        $this->cartWith($product, 9);

        $cart = $this->carryOver->mergeCart($user, $this->guestSessionId);

        $this->assertSame([$product->id.'/-' => 20], $this->lines($cart));
    }

    #[Test]
    public function a_variant_is_a_different_line_from_its_product(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->priced('10.00')->stocked(50)->create();
        $variant = ProductVariant::factory()->for($product)->priced('12.00')->create();
        $mine = $this->cartWith($product, 1, $user);
        $guest = $this->cartWith($variant, 2);

        $cart = $this->carryOver->mergeCart($user, $this->guestSessionId);

        $expected = [$product->id.'/-' => 1, $product->id.'/'.$variant->id => 2];
        ksort($expected);
        $this->assertSame($expected, $this->lines($cart));
    }

    #[Test]
    public function the_accounts_discount_code_is_kept_and_the_guests_is_taken_when_there_is_none(): void
    {
        $user = User::factory()->create();
        $product = $this->product();
        $accountCode = Coupon::factory()->create(['code' => 'MINE']);
        $guestCode = Coupon::factory()->create(['code' => 'THEIRS']);

        $mine = $this->cartWith($product, 1, $user);
        $mine->forceFill(['coupon_id' => $accountCode->id])->save();
        $this->cartWith($product, 1)->forceFill(['coupon_id' => $guestCode->id])->save();

        $this->assertSame($accountCode->id, $this->carryOver->mergeCart($user, $this->guestSessionId)->coupon_id, 'the account already had a code');

        // An account cart with no code takes the guest's.
        $other = User::factory()->create();
        $this->cartWith($product, 1, $other);
        $this->guestSessionId = Str::random(40);
        $this->cartWith($product, 1)->forceFill(['coupon_id' => $guestCode->id])->save();

        $this->assertSame($guestCode->id, $this->carryOver->mergeCart($other, $this->guestSessionId)->coupon_id);
    }

    #[Test]
    public function only_the_browsers_own_open_guest_cart_is_touched(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        $product = $this->product();

        $this->cartWith($product, 1, $stranger);                                    // another person's cart
        $converted = $this->cartWith($product, 1);                                  // this session, but already an order
        $converted->forceFill(['status' => CartManager::CONVERTED])->save();
        $theirs = Cart::create(['session_id' => Str::random(40), 'status' => CartManager::OPEN, 'currency' => 'USD']); // another browser
        $theirs->items()->create(['product_id' => $product->id, 'quantity' => 3, 'unit_price' => '10.00', 'total' => '30.00']);

        $this->assertNull($this->carryOver->mergeCart($user, $this->guestSessionId));

        $this->assertNull(Cart::query()->where('user_id', $user->id)->first(), 'nothing was given to the user');
        $this->assertNull($theirs->fresh()->user_id);
        $this->assertSame(CartManager::CONVERTED, $converted->fresh()->status);
    }

    #[Test]
    public function carts_of_products_merge_whatever_currency_the_older_one_was_made_in(): void
    {
        $user = User::factory()->create();
        $first = $this->product();
        $second = $this->product('5.00');
        $mine = $this->cartWith($first, 1, $user);
        // The shop's currency changed after this cart was made. Products are priced again at checkout, in today's.
        $mine->forceFill(['currency' => 'EUR'])->save();
        $this->cartWith($second, 2);

        $cart = $this->carryOver->mergeCart($user, $this->guestSessionId);

        $this->assertSame($mine->id, $cart->id);
        $this->assertEquals([$first->id.'/-' => 1, $second->id.'/-' => 2], $this->lines($cart));
        $this->assertNull(session('warning'));
    }

    #[Test]
    public function an_empty_guest_cart_is_simply_discarded(): void
    {
        $user = User::factory()->create();
        $mine = $this->cartWith($this->product(), 1, $user);
        Cart::create(['session_id' => $this->guestSessionId, 'status' => CartManager::OPEN, 'currency' => 'USD']);

        $cart = $this->carryOver->mergeCart($user, $this->guestSessionId);

        $this->assertSame($mine->id, $cart->id);
        $this->assertSame(1, Cart::query()->count());
    }

    // --- A checkout the guest had started --------------------------------------------------------

    #[Test]
    public function a_checkout_started_as_a_guest_carries_on_from_the_accounts_cart(): void
    {
        [$order, $payment, $product] = $this->placeOrder(price: '10.00', quantity: 2);
        $user = User::factory()->create();
        $other = $this->product('5.00');
        $mine = $this->cartWith($other, 1, $user);

        $this->carryOver->mergeCart($user, $this->guestSessionId);

        $this->assertSame($mine->id, $order->fresh()->cart_id, 'the order is still tied to a cart that exists');
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);

        // Paying it takes what was bought out of that cart, and leaves what the account had put there.
        $this->deliverStripeEvent($this->stripeEvent('checkout.session.completed', $this->sessionFor($payment)))->assertOk();

        $this->assertSame(OrderPaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertSame([$other->id.'/-' => 1], $this->lines($mine->fresh()));
    }

    #[Test]
    public function starting_again_after_signing_in_closes_the_checkout_the_guest_had_started(): void
    {
        [$first, $firstPayment] = $this->placeOrder(price: '10.00', quantity: 2);
        $user = User::factory()->create();
        $this->cartWith($this->product('5.00'), 1, $user);

        $this->carryOver->mergeCart($user, $this->guestSessionId);

        $this->actingAs($user)->post(route('shop.checkout.store'), $this->checkoutPayload())->assertRedirect();

        $this->assertSame(OrderStatus::Cancelled, $first->fresh()->status, 'the earlier checkout was found and replaced');
        $this->assertSame([$firstPayment->provider_reference], $this->stripe->expired, 'and closed at Stripe, so it cannot also be paid');
        $this->assertSame(1, Order::query()->where('status', OrderStatus::Pending->value)->count());
    }

    #[Test]
    public function an_emptied_guest_cart_that_a_checkout_was_started_from_is_kept_for_it(): void
    {
        [$order] = $this->placeOrder(price: '10.00', quantity: 2);
        $user = User::factory()->create();
        CartItem::query()->delete(); // they took everything out after being sent to pay

        $cart = $this->carryOver->mergeCart($user, $this->guestSessionId);

        $this->assertNotNull($cart, 'the cart is not thrown away with a payable checkout behind it');
        $this->assertSame($user->id, $cart->user_id);
        $this->assertSame($cart->id, $order->fresh()->cart_id);
    }

    // --- Each way of signing in ------------------------------------------------------------------

    #[Test]
    public function signing_in_with_a_password_brings_the_guests_cart_along(): void
    {
        $user = User::factory()->create();
        $product = $this->product();
        $this->addToCart($product, 2)->assertSessionHasNoErrors();
        $this->assertSame(1, Cart::query()->whereNull('user_id')->count());

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('dashboard', absolute: false));

        $cart = Cart::query()->where('user_id', $user->id)->sole();
        $this->assertSame([$product->id.'/-' => 2], $this->lines($cart));
    }

    #[Test]
    public function the_cart_waits_for_the_second_step_when_the_account_has_two_factor(): void
    {
        $user = $this->userWithTwoFactor();
        $product = $this->product();
        $this->addToCart($product, 2);

        // The password alone is not signing in: the cart is not handed to the account yet.
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('two-factor.login'));
        $this->assertNull(Cart::query()->where('user_id', $user->id)->first());
        $this->assertSame(1, Cart::query()->whereNull('user_id')->count());

        $this->post(route('two-factor.login'), ['recovery_code' => 'TEST-RECOVERY-CODE'])->assertRedirect(route('dashboard', absolute: false));

        $this->assertSame([$product->id.'/-' => 2], $this->lines(Cart::query()->where('user_id', $user->id)->sole()));
    }

    #[Test]
    public function a_cart_filled_in_while_the_code_is_awaited_comes_along_too(): void
    {
        $user = $this->userWithTwoFactor();
        $before = $this->product('10.00');
        $while = $this->product('5.00');
        $this->addToCart($before, 2);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('two-factor.login'));

        // The password step moved the browser to another session. A browser would send that one from now on, and
        // whatever goes in the cart while the code is typed in is kept under it.
        $this->withCookie(config('session.cookie'), app('session.store')->getId());
        $this->assertNotSame($this->guestSessionId, app('session.store')->getId());
        $this->addToCart($while, 3);
        $this->assertSame(2, Cart::query()->whereNull('user_id')->count(), 'one cart for each of the two sessions');

        $this->post(route('two-factor.login'), ['recovery_code' => 'TEST-RECOVERY-CODE'])->assertRedirect(route('dashboard', absolute: false));

        $expected = [$before->id.'/-' => 2, $while->id.'/-' => 3];
        ksort($expected);
        $this->assertSame($expected, $this->lines(Cart::query()->where('user_id', $user->id)->sole()), 'nothing was left behind');
        $this->assertSame(1, Cart::query()->count(), 'and there is only the one cart');
    }

    #[Test]
    public function registering_keeps_the_cart_the_browser_had(): void
    {
        $product = $this->product();
        $this->addToCart($product, 3);

        $this->post('/register', [
            'nickname' => 'newcomer',
            'email' => 'newcomer@example.test',
            'password' => 'a-Strong-pass-phrase-1!',
            'password_confirmation' => 'a-Strong-pass-phrase-1!',
        ])->assertRedirect();

        $user = User::query()->where('email', 'newcomer@example.test')->sole();
        $this->assertSame([$product->id.'/-' => 3], $this->lines(Cart::query()->where('user_id', $user->id)->sole()));
    }

    // --- The orders ------------------------------------------------------------------------------

    #[Test]
    public function a_verified_account_takes_the_guest_orders_placed_with_its_email_whatever_the_case(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.test']);
        $mine = $this->guestOrder('Ada@Example.TEST');
        $payment = Payment::factory()->create(['order_id' => $mine->id]);

        $this->assertSame(1, $this->carryOver->claimOrders($user));

        $mine->refresh();
        $this->assertSame($user->id, $mine->user_id);
        $this->assertTrue($mine->isOwnedBy($user));
        $this->assertTrue($payment->fresh()->isOwnedBy($user), 'its payment belongs to whoever owns the order');
        $this->assertSame('Ada@Example.TEST', $mine->customer_email, 'what the customer typed is left alone');

        $event = $mine->events()->where('type', OrderEvent::CLAIMED)->sole();
        $this->assertSame($user->id, $event->user_id);
        $this->assertStringContainsString('ada@example.test', (string) $event->message);

        $this->assertSame(0, $this->carryOver->claimOrders($user), 'claiming again changes nothing');
        $this->assertSame(1, $mine->events()->where('type', OrderEvent::CLAIMED)->count());
    }

    #[Test]
    public function an_account_whose_email_is_not_verified_takes_nothing(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'ada@example.test']);
        $order = $this->guestOrder('ada@example.test');

        $this->assertSame(0, $this->carryOver->claimOrders($user));

        $this->assertNull($order->fresh()->user_id);
        $this->assertNull($order->fresh()->owner_type);
    }

    #[Test]
    public function only_orders_for_that_address_and_with_no_owner_are_taken(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.test']);
        $someoneElses = $this->guestOrder('bob@example.test');
        $similar = $this->guestOrder('ada@example.test.au');
        $subaddress = $this->guestOrder('ada+shop@example.test');
        $other = User::factory()->create();
        $alreadyOwned = Order::factory()->forUser($other)->paid()->create(['customer_email' => 'ada@example.test']);
        $placedByAUser = $this->guestOrder('ada@example.test', ['user_id' => $other->id]);

        $this->assertSame(0, $this->carryOver->claimOrders($user));

        foreach ([$someoneElses, $similar, $subaddress] as $order) {
            $this->assertNull($order->fresh()->user_id);
        }

        $this->assertTrue($alreadyOwned->fresh()->isOwnedBy($other), 'an order that has an owner is never reassigned');
        $this->assertSame($other->id, $placedByAUser->fresh()->user_id);
        $this->assertNull($placedByAUser->fresh()->owner_type);
    }

    #[Test]
    public function verifying_the_email_hands_over_the_orders_placed_before_the_account_existed(): void
    {
        $order = $this->guestOrder('ada@example.test');
        $user = User::factory()->unverified()->create(['email' => 'ada@example.test']);

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->actingAs($user)->get($url)->assertRedirect();

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertTrue($order->fresh()->isOwnedBy($user));
        $this->assertSame($user->id, $order->fresh()->user_id);
    }

    #[Test]
    public function the_verified_event_alone_is_enough(): void
    {
        $order = $this->guestOrder('ada@example.test');
        $user = User::factory()->unverified()->create(['email' => 'ada@example.test']);
        $user->markEmailAsVerified();

        event(new Verified($user));

        $this->assertTrue($order->fresh()->isOwnedBy($user));
    }

    #[Test]
    public function an_administrator_verifying_the_address_hands_over_the_orders_too(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create()->assignRole('admin');
        $single = User::factory()->unverified()->create(['email' => 'single@example.test']);
        $bulk = User::factory()->unverified()->create(['email' => 'bulk@example.test']);
        $singleOrder = $this->guestOrder('single@example.test');
        $bulkOrder = $this->guestOrder('bulk@example.test');

        $this->actingAs($admin)->put(route('acp.users.verify', $single))->assertRedirect();
        $this->assertNotNull($single->fresh()->email_verified_at);
        $this->assertTrue($singleOrder->fresh()->isOwnedBy($single), 'verified one by one');

        $this->actingAs($admin)->patch(route('acp.users.bulk-update'), ['action' => 'verify', 'ids' => [$bulk->id]])->assertRedirect();
        $this->assertNotNull($bulk->fresh()->email_verified_at);
        $this->assertTrue($bulkOrder->fresh()->isOwnedBy($bulk), 'and in bulk');
    }

    #[Test]
    public function an_address_that_was_already_verified_is_not_announced_again(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create()->assignRole('admin');
        $user = User::factory()->create();
        Event::fake([Verified::class]);

        $this->actingAs($admin)->put(route('acp.users.verify', $user))->assertRedirect();

        Event::assertNotDispatched(Verified::class);
    }

    #[Test]
    public function signing_in_hands_over_the_orders_of_a_verified_account(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.test']);
        $order = $this->guestOrder('ada@example.test');

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();

        $this->assertTrue($order->fresh()->isOwnedBy($user));
    }

    #[Test]
    public function a_password_alone_does_not_hand_over_orders_when_there_is_a_second_step(): void
    {
        $user = $this->userWithTwoFactor();
        $order = $this->guestOrder('ada@example.test');

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('two-factor.login'));
        $this->assertNull($order->fresh()->user_id);

        $this->post(route('two-factor.login'), ['recovery_code' => 'TEST-RECOVERY-CODE']);
        $this->assertTrue($order->fresh()->isOwnedBy($user));
    }

    #[Test]
    public function a_claimed_order_appears_in_the_customers_dashboard_and_order_history(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.test']);
        $order = $this->guestOrder('ada@example.test');
        $this->carryOver->claimOrders($user);

        $this->actingAs($user)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('shop.summary.orders', 1)
            ->where('shop.recent_orders.0.number', $order->number));

        $this->actingAs($user)->get(route('shop.orders'))->assertInertia(fn (Assert $page) => $page->where('orders.total', 1));
    }

    // --- Telling a guest they can keep the order -------------------------------------------------

    #[Test]
    public function a_guest_looking_at_their_own_guest_order_is_told_how_to_keep_it(): void
    {
        [$order, $payment] = $this->placeOrder();
        $this->deliverStripeEvent($this->stripeEvent('checkout.session.completed', $this->sessionFor($payment)))->assertOk();
        $url = URL::signedRoute('shop.checkout.complete', ['order' => $order->public_id]);

        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('claim', ['email' => 'buyer@example.com']));
    }

    #[Test]
    public function nobody_else_is_offered_to_keep_it(): void
    {
        [$order] = $this->placeOrder();
        $url = URL::signedRoute('shop.checkout.complete', ['order' => $order->public_id]);

        // Someone who is already signed in has an account to keep it in.
        $this->actingAs(User::factory()->create())->get($url)->assertInertia(fn (Assert $page) => $page->where('claim', null));

        // An order that already belongs to an account is not a guest order.
        $owner = User::factory()->create();
        $order->assignOwner($owner)->save();
        $this->app['auth']->forgetGuards();
        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('claim', null));
    }
}
