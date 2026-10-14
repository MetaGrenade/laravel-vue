<?php

namespace Tests\Feature\Commerce;

use App\Models\Cart;
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
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
     * @return array<string, int> Quantity of each product in the cart, by product id.
     */
    private function lines(Cart $cart): array
    {
        return $cart->items()->get()->mapWithKeys(fn ($item) => [$item->product_id.'/'.($item->product_variant_id ?? '-') => $item->quantity])->all();
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
        $this->assertSame([$a->id.'/-' => 5, $b->id.'/-' => 1, $c->id.'/-' => 4], $this->lines($cart), 'the same line is added to, the rest move over');
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

        $this->assertSame([$product->id.'/-' => 1, $product->id.'/'.$variant->id => 2], $this->lines($cart));
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
    public function an_empty_guest_cart_is_simply_discarded(): void
    {
        $user = User::factory()->create();
        $mine = $this->cartWith($this->product(), 1, $user);
        Cart::create(['session_id' => $this->guestSessionId, 'status' => CartManager::OPEN, 'currency' => 'USD']);

        $cart = $this->carryOver->mergeCart($user, $this->guestSessionId);

        $this->assertSame($mine->id, $cart->id);
        $this->assertSame(1, Cart::query()->count());
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
        $user = User::factory()->create();
        $secret = TwoFactorAuthenticator::generateSecret();
        $user->forceFill([
            'two_factor_secret' => TwoFactorAuthenticator::encryptSecret($secret),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => TwoFactorAuthenticator::encryptRecoveryCodes(['TEST-RECOVERY-CODE']),
        ])->save();
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
        $user = User::factory()->create(['email' => 'ada@example.test']);
        $secret = TwoFactorAuthenticator::generateSecret();
        $user->forceFill([
            'two_factor_secret' => TwoFactorAuthenticator::encryptSecret($secret),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => TwoFactorAuthenticator::encryptRecoveryCodes(['TEST-RECOVERY-CODE']),
        ])->save();
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
