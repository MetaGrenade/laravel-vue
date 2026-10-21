<?php

namespace Tests\Feature\Tebex;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\TebexPackage;
use App\Models\User;
use App\Payments\Data\CheckoutClosure;
use App\Payments\Data\CheckoutContext;
use App\Payments\Exceptions\BuyerIdentityRejected;
use App\Payments\Exceptions\PaymentException;
use App\Payments\PaymentManager;
use App\Payments\Tebex\SignInStep;
use App\Payments\Tebex\TebexBasketBusy;
use App\Payments\Tebex\TebexCatalogue;
use App\Payments\Tebex\TebexException;
use App\Payments\Tebex\TebexSignIn;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeTebexBaskets;
use Tests\Support\InteractsWithCommerce;
use Tests\Support\InteractsWithTebex;
use Tests\TestCase;

/**
 * Selling for the games: a store for Minecraft or Overwolf asks the buyer's username at checkout, and a store for Discord or a
 * Steam-based game (FiveM, Rust...) sends them to sign in through Tebex and carries on when they are back.
 */
class TebexGamePlatformsTest extends TestCase
{
    use InteractsWithCommerce;
    use InteractsWithTebex;
    use RefreshDatabase;

    private FakeTebexBaskets $tebex;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerce();
        $this->setUpTebex();
        $this->sellThroughTebex();
    }

    /**
     * A store set up for this platform, with a package in the cart.
     */
    private function storeFor(string $platform): TebexPackage
    {
        $this->tebex = $this->fakeTebexBaskets($this->tebexStoreOn($platform));
        // Tebex will not take a package into a basket for a store that identifies its buyers, until it knows who.
        $this->tebex->requiresIdentity = true;
        app(TebexCatalogue::class)->sync();

        // The fixture's VIP package, at 9.99 (the synchronisation copied it).
        $package = TebexPackage::query()->where('tebex_id', 50001)->firstOrFail();
        $this->post(route('shop.cart.packages.store'), ['package_id' => $package->tebex_id, 'quantity' => 2])->assertSessionHas('success');

        return $package;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function checkout(array $data = [])
    {
        return $this->post(route('shop.checkout.store'), ['email' => 'buyer@example.com', 'name' => 'Ada Buyer', 'token' => (string) Str::uuid(), ...$data]);
    }

    private function continueUrl(Order $order): string
    {
        return URL::signedRoute('shop.tebex.continue', ['order' => $order->public_id]);
    }

    // --- A username (Minecraft, Overwolf) ----------------------------------------------------------------------

    #[Test]
    public function the_checkout_page_asks_a_minecraft_buyer_for_their_username(): void
    {
        $this->storeFor('Minecraft: Java Edition');

        $this->get(route('shop.checkout'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('tebex', true)
            ->where('identity.kind', 'username')
            ->where('identity.username_label', 'Minecraft username')
            ->where('identity.platform', 'Minecraft: Java Edition')
            ->where('needsShipping', false)
            ->where('billingRequired', false));
    }

    #[Test]
    public function the_username_goes_into_the_basket_and_the_packages_follow_with_the_buyers_id(): void
    {
        $this->storeFor('Minecraft: Java Edition');

        $response = $this->checkout(['game_username' => '  Notch ']);

        $order = Order::query()->sole();
        $payment = Payment::query()->sole();
        $response->assertRedirect($payment->checkout_url);
        $this->assertStringStartsWith('https://pay.tebex.io/bsk', $payment->checkout_url);

        $this->assertSame('Notch', $this->tebex->calls('create')[0]['body']['username'], 'trimmed');
        $id = $this->tebex->baskets[$payment->provider_reference]['username_id'];
        $this->assertGreaterThan(0, $id);
        $this->assertSame(['username_id' => $id], $this->tebex->calls('add')[0]['body']['variable_data']);
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame(['username' => 'Notch', 'username_id' => $id], $payment->raw['buyer']);
    }

    #[Test]
    public function the_provider_refuses_a_blank_username_by_itself_without_asking_tebex(): void
    {
        $this->storeFor('Minecraft: Java Edition');
        $package = TebexPackage::query()->where('tebex_id', 50001)->firstOrFail();
        $order = Order::factory()->create(['payment_provider' => 'tebex']);
        $order->items()->create(['tebex_package_id' => $package->id, 'quantity' => 1, 'unit_price' => '9.99', 'subtotal' => '9.99']);
        $provider = app(PaymentManager::class)->provider('tebex');

        foreach ([null, '', '   '] as $blank) {
            try {
                $provider->startCheckout($order, new CheckoutContext('https://shop.test/done', 'https://shop.test/cart', now()->addHour(), buyerUsername: $blank));
                $this->fail('A basket was made for a store that needs a username, with none.');
            } catch (BuyerIdentityRejected $exception) {
                $this->assertSame('game_username', $exception->field);
                $this->assertSame('Enter your minecraft username.', $exception->getMessage());
            }
        }

        $this->assertSame([], $this->tebex->calls('create'));
    }

    #[Test]
    public function an_overwolf_buyer_is_asked_in_its_own_words(): void
    {
        $this->storeFor('Overwolf');

        $this->get(route('shop.checkout'))->assertInertia(fn (Assert $page) => $page->where('identity.kind', 'username')->where('identity.username_label', 'Overwolf username'));
    }

    #[Test]
    public function a_username_is_required_and_has_to_be_sensible(): void
    {
        $this->storeFor('Minecraft: Java Edition');

        $this->checkout()->assertSessionHasErrors(['game_username' => 'Enter your minecraft username.']);
        $this->checkout(['game_username' => '   '])->assertSessionHasErrors('game_username');
        $this->checkout(['game_username' => str_repeat('a', 65)])->assertSessionHasErrors(['game_username' => 'That minecraft username is too long.']);
        $this->checkout(['game_username' => "Evil\x00Name"])->assertSessionHasErrors(['game_username' => 'That minecraft username has characters it cannot have.']);

        $this->assertSame(0, Order::query()->count());
        $this->assertSame([], $this->tebex->calls('create'));
    }

    #[Test]
    public function a_username_tebex_does_not_recognise_is_sent_back_to_the_form_and_nothing_is_kept(): void
    {
        $this->storeFor('Minecraft: Java Edition');
        $this->tebex->unknownUsernames[] = 'Nobody';

        $this->checkout(['game_username' => 'Nobody'])
            ->assertRedirect()
            ->assertSessionHasErrors(['game_username' => 'Tebex did not recognise that minecraft username. Please check it and try again.']);

        $this->assertSame(OrderStatus::Cancelled, Order::query()->sole()->status);
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame([], $this->tebex->calls('add'), 'nothing was put in a basket nobody owns');
    }

    #[Test]
    public function correcting_a_username_on_the_same_form_makes_a_new_order_not_the_cancelled_one(): void
    {
        $this->storeFor('Minecraft: Java Edition');
        $this->tebex->unknownUsernames[] = 'Nobody';
        $token = (string) Str::uuid();

        $this->checkout(['game_username' => 'Nobody', 'token' => $token])->assertSessionHasErrors('game_username');
        $second = $this->checkout(['game_username' => 'Notch', 'token' => $token]);

        $orders = Order::query()->orderBy('id')->get();
        $this->assertCount(2, $orders);
        $this->assertSame(OrderStatus::Cancelled, $orders[0]->status);
        $this->assertNull($orders[0]->idempotency_key, 'the key is free for the corrected attempt');
        $this->assertSame(OrderStatus::Pending, $orders[1]->status);
        $second->assertRedirect(Payment::query()->sole()->checkout_url);
    }

    #[Test]
    public function a_username_is_only_asked_for_by_a_store_that_wants_one(): void
    {
        $this->storeFor('FiveM');

        $this->checkout(['game_username' => 'Ignored'])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertArrayNotHasKey('username', $this->tebex->calls('create')[0]['body']);
    }

    #[Test]
    public function who_a_package_is_for_is_shown_to_the_customer_and_to_staff(): void
    {
        $this->storeFor('Minecraft: Java Edition');
        $this->checkout(['game_username' => 'Notch']);
        $order = Order::query()->sole();
        $id = Payment::query()->sole()->raw['buyer']['username_id'];
        $this->seed(RolePermissionSeeder::class);

        $this->get(URL::signedRoute('shop.checkout.complete', ['order' => $order->public_id]))
            ->assertInertia(fn (Assert $page) => $page->where('order.game_account', 'Notch'));

        $this->actingAs(User::factory()->create()->assignRole('admin'))->get(route('acp.commerce.orders.show', $order))
            ->assertInertia(fn (Assert $page) => $page->where('order.game_account', ['username' => 'Notch', 'id' => $id]));
    }

    #[Test]
    public function an_order_that_nobody_was_identified_for_shows_no_account(): void
    {
        $this->tebex = $this->fakeTebexBaskets();
        app(TebexCatalogue::class)->sync();
        $package = TebexPackage::query()->where('tebex_id', 50001)->firstOrFail();
        $this->post(route('shop.cart.packages.store'), ['package_id' => $package->tebex_id, 'quantity' => 1]);
        $this->checkout();
        $order = Order::query()->sole();

        $this->get(URL::signedRoute('shop.checkout.complete', ['order' => $order->public_id]))->assertInertia(fn (Assert $page) => $page->where('order.game_account', null));
        $this->assertNull(Payment::query()->sole()->raw['buyer']);
    }

    // --- Signing in (Discord, FiveM, Rust and the rest) ----------------------------------------------------------

    #[Test]
    public function a_buyer_of_a_store_that_makes_them_sign_in_is_told_so_and_is_asked_for_nothing_else(): void
    {
        $this->storeFor('FiveM');

        $this->get(route('shop.checkout'))->assertInertia(fn (Assert $page) => $page->where('identity.kind', 'sign-in')->where('identity.username_label', null));
    }

    #[Test]
    public function checking_out_makes_the_basket_but_puts_nothing_in_it_and_sends_the_buyer_to_the_shops_own_link(): void
    {
        $this->storeFor('FiveM');

        $response = $this->checkout();

        $order = Order::query()->sole();
        $payment = Payment::query()->sole();
        $response->assertRedirect($this->continueUrl($order));
        $this->assertSame($this->continueUrl($order), $payment->checkout_url);
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertTrue($payment->raw['sign_in']);
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertCount(1, $this->tebex->calls('create'));
        $this->assertSame([], $this->tebex->calls('add'), 'Tebex would refuse a package before the buyer has signed in');
        $this->assertSame([], $this->tebex->baskets[$payment->provider_reference]['lines']);
    }

    #[Test]
    public function the_link_sends_a_buyer_who_has_not_signed_in_to_sign_in_and_to_come_back_to_it(): void
    {
        $this->storeFor('FiveM');
        $this->checkout();
        $order = Order::query()->sole();

        $this->get($this->continueUrl($order))->assertRedirect('https://auth.tebex.test/steam');

        $call = $this->tebex->calls('auth')[0];
        parse_str((string) parse_url($call['url'], PHP_URL_QUERY), $query);
        $this->assertSame($this->continueUrl($order), $query['returnUrl'], 'back to this same link');
        $this->assertStringContainsString('/baskets/'.Payment::query()->sole()->provider_reference.'/auth', $call['path']);
        $this->assertSame([], $this->tebex->calls('add'));
    }

    #[Test]
    public function when_tebex_offers_several_ways_to_sign_in_the_buyer_chooses(): void
    {
        $this->storeFor('FiveM');
        $this->tebex->signInOptions = [['name' => 'Steam', 'url' => 'https://auth.tebex.test/steam'], ['name' => 'Cfx.re', 'url' => 'https://auth.tebex.test/cfx']];
        $this->checkout();

        $this->get($this->continueUrl(Order::query()->sole()))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('commerce/TebexSignIn')
            ->where('options', [['name' => 'Steam', 'url' => 'https://auth.tebex.test/steam'], ['name' => 'Cfx.re', 'url' => 'https://auth.tebex.test/cfx']])
            ->where('cancel', route('shop.cart')));
    }

    #[Test]
    public function a_way_to_sign_in_that_is_not_a_web_address_is_not_offered(): void
    {
        $this->storeFor('FiveM');
        $this->tebex->signInOptions = [['name' => 'Evil', 'url' => 'javascript:alert(1)'], ['name' => 'Steam', 'url' => 'https://auth.tebex.test/steam']];
        $this->checkout();

        $this->get($this->continueUrl(Order::query()->sole()))->assertRedirect('https://auth.tebex.test/steam');
    }

    #[Test]
    public function when_tebex_offers_no_way_to_sign_in_the_buyer_is_told_and_nothing_is_changed(): void
    {
        $this->storeFor('FiveM');
        $this->tebex->signInOptions = [];
        $this->checkout();
        $order = Order::query()->sole();

        $this->get($this->continueUrl($order))->assertRedirect(route('shop.cart'))->assertSessionHas('error', fn (string $error) => str_contains($error, 'did not offer a way to sign in'));

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    #[Test]
    public function once_signed_in_the_packages_go_in_with_the_buyers_id_and_the_buyer_goes_to_pay(): void
    {
        $this->storeFor('FiveM');
        $this->checkout();
        $order = Order::query()->sole();
        $payment = Payment::query()->sole();
        $this->tebex->authorise($payment->provider_reference, 76561198000000042, 'ProGamer');

        $this->get($this->continueUrl($order))->assertRedirect("https://pay.tebex.io/{$payment->provider_reference}");

        $adds = $this->tebex->calls('add');
        $this->assertCount(1, $adds);
        $this->assertSame(['package_id' => 'vip-rank', 'quantity' => 2, 'variable_data' => ['username_id' => 76561198000000042]], $adds[0]['body']);
        $payment->refresh();
        $this->assertSame(['username' => 'ProGamer', 'username_id' => 76561198000000042], $payment->raw['buyer']);
        $this->assertSame('19.98', $payment->raw['quoted']['base_price']);
        $this->assertFalse($payment->raw['price_differs']);
        $this->assertTrue($payment->raw['sign_in'], 'what was there is kept');
        $this->assertSame($this->continueUrl($order), $payment->checkout_url, 'coming back to the link always works');
    }

    #[Test]
    public function opening_the_link_again_does_not_put_the_packages_in_twice(): void
    {
        $this->storeFor('FiveM');
        $this->checkout();
        $order = Order::query()->sole();
        $ident = Payment::query()->sole()->provider_reference;
        $this->tebex->authorise($ident);

        $this->get($this->continueUrl($order))->assertRedirect();
        $this->get($this->continueUrl($order))->assertRedirect("https://pay.tebex.io/{$ident}");

        $this->assertCount(1, $this->tebex->calls('add'));
        $this->assertSame(2, $this->tebex->baskets[$ident]['lines'][50001]['quantity']);
    }

    #[Test]
    public function a_basket_that_does_not_hold_what_the_order_does_is_made_right(): void
    {
        $this->storeFor('FiveM');
        $this->checkout();
        $order = Order::query()->sole();
        $ident = Payment::query()->sole()->provider_reference;
        $this->tebex->authorise($ident);
        $this->get($this->continueUrl($order));

        // Something else got in (or the quantity changed) at Tebex's end.
        $this->tebex->baskets[$ident]['lines'][50001]['quantity'] = 5;
        $this->get($this->continueUrl($order))->assertRedirect("https://pay.tebex.io/{$ident}");

        $this->assertCount(1, $this->tebex->calls('remove'));
        $this->assertSame(2, $this->tebex->baskets[$ident]['lines'][50001]['quantity']);
    }

    #[Test]
    public function a_buyer_who_came_back_without_signing_in_is_sent_to_sign_in_again(): void
    {
        $this->storeFor('FiveM');
        $this->checkout();
        $order = Order::query()->sole();

        $this->get($this->continueUrl($order))->assertRedirect('https://auth.tebex.test/steam');
        $this->get($this->continueUrl($order))->assertRedirect('https://auth.tebex.test/steam');

        $this->assertCount(2, $this->tebex->calls('auth'));
        $this->assertSame([], $this->tebex->calls('add'));
    }

    #[Test]
    public function a_basket_that_has_been_paid_is_not_touched_and_the_buyer_goes_to_their_order(): void
    {
        $this->storeFor('FiveM');
        $this->checkout();
        $order = Order::query()->sole();
        $ident = Payment::query()->sole()->provider_reference;
        $this->tebex->authorise($ident);
        $this->get($this->continueUrl($order));
        $this->tebex->markPaid($ident);
        $addsBefore = count($this->tebex->calls('add'));

        $this->get($this->continueUrl($order))->assertRedirect(URL::signedRoute('shop.checkout.complete', ['order' => $order->public_id]));

        $this->assertCount($addsBefore, $this->tebex->calls('add'));
        $this->assertSame([], $this->tebex->calls('remove'));
    }

    #[Test]
    public function an_order_that_is_no_longer_open_cannot_be_carried_on(): void
    {
        $this->storeFor('FiveM');
        $this->checkout();
        $order = Order::query()->sole();

        $this->travel(61)->minutes();
        $this->get($this->continueUrl($order))->assertRedirect(route('shop.cart'))->assertSessionHas('error', 'This checkout has expired. Please start again.');

        $order->forceFill(['status' => OrderStatus::Cancelled])->save();
        $this->get($this->continueUrl($order))->assertRedirect(route('shop.cart'))->assertSessionHas('error');
        $this->assertSame([], $this->tebex->calls('add'));
    }

    #[Test]
    public function an_order_that_is_paid_goes_to_its_page(): void
    {
        $this->storeFor('FiveM');
        $this->checkout();
        $order = Order::query()->sole();
        $order->forceFill(['status' => OrderStatus::Processing, 'payment_status' => OrderPaymentStatus::Paid, 'paid_at' => now()])->save();

        $this->get($this->continueUrl($order))->assertRedirect(URL::signedRoute('shop.checkout.complete', ['order' => $order->public_id]));
        $this->assertSame([], $this->tebex->calls('auth'));
    }

    #[Test]
    public function the_link_is_signed_and_only_for_a_tebex_order(): void
    {
        $this->storeFor('FiveM');
        $this->checkout();
        $order = Order::query()->sole();

        $this->get(route('shop.tebex.continue', ['order' => $order->public_id]))->assertForbidden();
        $this->get(route('shop.tebex.continue', ['order' => $order->public_id]).'?signature=forged')->assertForbidden();

        $other = Order::factory()->create(['payment_provider' => 'stripe']);
        $this->get(URL::signedRoute('shop.tebex.continue', ['order' => $other->public_id]))->assertNotFound();

        // An order paid another way is not carried on through Tebex even if a payment of Tebex's is attached to it.
        Payment::factory()->create(['order_id' => $other->id, 'provider' => 'tebex', 'provider_reference' => 'bsk-not-mine']);
        $this->get(URL::signedRoute('shop.tebex.continue', ['order' => $other->public_id]))->assertNotFound();
        $this->assertSame([], $this->tebex->calls('get'));

        $tebexOrderWithoutPayment = Order::factory()->create(['payment_provider' => 'tebex']);
        $this->get(URL::signedRoute('shop.tebex.continue', ['order' => $tebexOrderWithoutPayment->public_id]))->assertNotFound();
        $this->get(URL::signedRoute('shop.tebex.continue', ['order' => 'no-such-order']))->assertNotFound();
    }

    #[Test]
    public function when_tebex_cannot_be_reached_the_buyer_is_told_to_try_again_and_the_order_is_left_as_it_was(): void
    {
        Exceptions::fake();
        $this->storeFor('FiveM');
        $this->checkout();
        $order = Order::query()->sole();
        $this->tebex->failOn('get', 503, 4);

        $this->get($this->continueUrl($order))->assertRedirect(route('shop.cart'))->assertSessionHas('error', 'We could not reach Tebex just now. Please try again in a moment.');

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        Exceptions::assertReported(TebexException::class);
    }

    #[Test]
    public function pressing_the_button_twice_comes_back_to_the_same_link(): void
    {
        $this->storeFor('FiveM');
        $token = (string) Str::uuid();

        $first = $this->checkout(['token' => $token]);
        $second = $this->checkout(['token' => $token]);

        $this->assertSame(1, Order::query()->count());
        $this->assertCount(1, $this->tebex->calls('create'));
        $this->assertSame($first->headers->get('Location'), $second->headers->get('Location'));
    }

    // --- Closing a checkout that was never signed in to ----------------------------------------------------------------

    #[Test]
    public function a_checkout_nobody_signed_in_to_closes_like_any_other_and_starting_again_replaces_it(): void
    {
        $this->storeFor('FiveM');
        $this->checkout();
        $first = Payment::query()->sole();

        $this->assertSame(CheckoutClosure::Closed, app(PaymentManager::class)->provider('tebex')->closeCheckout($first));

        $this->checkout()->assertRedirect();
        $this->assertSame(OrderStatus::Cancelled, Order::query()->orderBy('id')->first()->status);
        $this->assertSame(2, Order::query()->count());
    }

    #[Test]
    public function a_basket_that_was_signed_in_to_is_emptied_when_the_checkout_is_closed(): void
    {
        $this->storeFor('FiveM');
        $this->checkout();
        $order = Order::query()->sole();
        $payment = Payment::query()->sole();
        $this->tebex->authorise($payment->provider_reference);
        $this->get($this->continueUrl($order));
        $this->assertNotEmpty($this->tebex->baskets[$payment->provider_reference]['lines']);

        $this->assertSame(CheckoutClosure::Closed, app(PaymentManager::class)->provider('tebex')->closeCheckout($payment));
        $this->assertSame([], $this->tebex->baskets[$payment->provider_reference]['lines']);
    }

    // --- Two requests at once, and an answer the shop will not sell on ---------------------------------------------------

    #[Test]
    public function a_second_visit_that_arrives_while_the_first_is_filling_the_basket_does_not_fill_it_again(): void
    {
        $this->storeFor('FiveM');
        $this->checkout();
        $order = Order::query()->sole();
        $payment = Payment::query()->sole();
        $ident = $payment->provider_reference;
        $this->tebex->authorise($ident);
        config(['tebex.basket.lock_wait_seconds' => 0]);

        // The other tab's visit lands just as Tebex receives the first one's package, before it is recorded: it reads the
        // basket as empty, which is what made every package go in twice.
        $second = null;
        $this->tebex->whenAsked = function (string $action) use (&$second, $order, $payment): void {
            if ($action === 'add') {
                $this->tebex->whenAsked = null;
                $second = app(TebexSignIn::class)->next($order, $payment);
            }
        };

        $first = app(TebexSignIn::class)->next($order, $payment);

        $this->assertSame(SignInStep::REDIRECT, $first->kind);
        $this->assertSame("https://pay.tebex.io/{$ident}", $first->url);
        $this->assertSame(SignInStep::FAILED, $second?->kind, 'it found the basket in use and left it alone');
        $this->assertStringContainsString('already being prepared', (string) $second->message);
        $this->assertCount(1, $this->tebex->calls('add'));
        $this->assertSame(2, $this->tebex->baskets[$ident]['lines'][50001]['quantity'], 'not 4: the buyer pays for what they ordered');
    }

    #[Test]
    public function a_visit_that_finds_the_basket_in_use_is_told_to_wait_and_changes_nothing(): void
    {
        $this->storeFor('FiveM');
        $this->checkout();
        $order = Order::query()->sole();
        $ident = Payment::query()->sole()->provider_reference;
        $this->tebex->authorise($ident);
        config(['tebex.basket.lock_wait_seconds' => 0]);
        $held = Cache::lock('tebex:basket:'.$ident, 60);
        $this->assertTrue($held->get());

        $this->get($this->continueUrl($order))
            ->assertRedirect(route('shop.cart'))
            ->assertSessionHas('error', fn (string $error) => str_contains($error, 'already being prepared'));

        $this->assertSame([], $this->tebex->calls('add'));
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);

        $held->release();
        $this->get($this->continueUrl($order))->assertRedirect("https://pay.tebex.io/{$ident}");
        $this->assertCount(1, $this->tebex->calls('add'));
    }

    #[Test]
    public function the_basket_is_free_again_after_a_visit_that_failed(): void
    {
        $this->storeFor('FiveM');
        $this->checkout();
        $order = Order::query()->sole();
        $ident = Payment::query()->sole()->provider_reference;
        $this->tebex->authorise($ident);
        config(['tebex.basket.lock_wait_seconds' => 0]);
        $this->tebex->failOn('get', 503, 4);

        $this->get($this->continueUrl($order))->assertRedirect(route('shop.cart'));
        $this->get($this->continueUrl($order))->assertRedirect("https://pay.tebex.io/{$ident}");
    }

    #[Test]
    public function a_checkout_cannot_be_closed_while_the_basket_is_being_filled(): void
    {
        $this->storeFor('FiveM');
        $this->checkout();
        $payment = Payment::query()->sole();
        config(['tebex.basket.lock_wait_seconds' => 0]);
        $held = Cache::lock('tebex:basket:'.$payment->provider_reference, 60);
        $this->assertTrue($held->get());

        try {
            app(PaymentManager::class)->provider('tebex')->closeCheckout($payment);
            $this->fail('A basket in use was closed.');
        } catch (TebexBasketBusy) {
            $this->assertSame([], $this->tebex->calls('remove'));
        }

        $held->release();
        $this->assertSame(CheckoutClosure::Closed, app(PaymentManager::class)->provider('tebex')->closeCheckout($payment));
    }

    #[Test]
    public function a_basket_with_no_place_to_pay_sends_the_buyer_back_with_a_message_and_not_to_an_error_page(): void
    {
        Exceptions::fake();
        $this->storeFor('FiveM');
        $this->checkout();
        $order = Order::query()->sole();
        $this->tebex->authorise(Payment::query()->sole()->provider_reference);
        $this->tebex->withoutCheckoutLink = true;

        $this->get($this->continueUrl($order))
            ->assertRedirect(route('shop.cart'))
            ->assertSessionHas('error', 'We could not start the payment. Please start the checkout again in a moment.');

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        Exceptions::assertReported(fn (PaymentException $exception) => str_contains($exception->getMessage(), 'did not say where to pay'));
    }

    #[Test]
    public function a_basket_priced_in_another_currency_sends_the_buyer_back_with_a_message_too(): void
    {
        Exceptions::fake();
        $this->storeFor('FiveM');
        $this->checkout();
        $order = Order::query()->sole();
        $this->tebex->authorise(Payment::query()->sole()->provider_reference);
        $this->tebex->currency = 'EUR';

        $this->get($this->continueUrl($order))
            ->assertRedirect(route('shop.cart'))
            ->assertSessionHas('error', 'We could not start the payment. Please start the checkout again in a moment.');

        Exceptions::assertReported(fn (PaymentException $exception) => str_contains($exception->getMessage(), 'priced order'));
    }
}
