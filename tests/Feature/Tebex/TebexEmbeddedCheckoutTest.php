<?php

namespace Tests\Feature\Tebex;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Payments\Exceptions\PaymentException;
use App\Payments\Tebex\TebexCatalogue;
use App\Payments\Tebex\TebexCheckoutMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeTebexBaskets;
use Tests\Support\InteractsWithCommerce;
use Tests\Support\InteractsWithTebex;
use Tests\TestCase;

/**
 * Paying on the shop's own page with Tebex.js, instead of being sent to Tebex's: a popup over the page, or inside it.
 *
 * What Tebex.js does in the browser is not tested here (it needs Tebex's side of the frame); this is everything the shop
 * decides: when the page is shown, what it is given, that nothing it reports settles an order, and that the content
 * security policy lets Tebex's frame in only while the checkout is embedded.
 */
class TebexEmbeddedCheckoutTest extends TestCase
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
     * A store set up for this platform (Universal when none is named), with two of the VIP package in the cart.
     */
    private function storeFor(?string $platform = null): void
    {
        $this->tebex = $this->fakeTebexBaskets($platform === null ? null : $this->tebexStoreOn($platform));
        $this->tebex->requiresIdentity = $platform !== null;
        app(TebexCatalogue::class)->sync();

        $this->post(route('shop.cart.packages.store'), ['package_id' => 50001, 'quantity' => 2])->assertSessionHas('success');
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

    private function completeUrl(Order $order): string
    {
        return URL::signedRoute('shop.checkout.complete', ['order' => $order->public_id]);
    }

    // --- The setting ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{0: mixed, 1: TebexCheckoutMode}>
     */
    public static function modes(): array
    {
        return [
            'popup' => ['popup', TebexCheckoutMode::Popup],
            'inline' => ['inline', TebexCheckoutMode::Inline],
            'redirect' => ['redirect', TebexCheckoutMode::Redirect],
            'in capitals, with spaces' => [' Inline ', TebexCheckoutMode::Inline],
            'something it does not know' => ['lightbox', TebexCheckoutMode::Redirect],
            'nothing' => ['', TebexCheckoutMode::Redirect],
            'not set' => [null, TebexCheckoutMode::Redirect],
        ];
    }

    #[Test]
    #[DataProvider('modes')]
    public function the_mode_is_read_from_the_setting_and_anything_else_is_the_hosted_page(mixed $setting, TebexCheckoutMode $expected): void
    {
        config(['tebex.checkout.mode' => $setting]);

        $this->assertSame($expected, TebexCheckoutMode::current());
        $this->assertSame($expected !== TebexCheckoutMode::Redirect, TebexCheckoutMode::current()->isEmbedded());
    }

    #[Test]
    public function the_hosted_page_is_the_default(): void
    {
        $this->assertSame(TebexCheckoutMode::Redirect, TebexCheckoutMode::current());
        $this->assertSame('redirect', config('tebex.checkout.mode'));
    }

    // --- Redirect (the default): Tebex's own page -------------------------------------------------------------------

    #[Test]
    public function by_default_the_buyer_is_sent_to_tebexs_own_page_as_before(): void
    {
        $this->storeFor();

        $response = $this->checkout();

        $payment = Payment::query()->sole();
        $response->assertRedirect("https://pay.tebex.io/{$payment->provider_reference}");
        $this->assertSame("https://pay.tebex.io/{$payment->provider_reference}", $payment->checkout_url);
    }

    #[Test]
    public function in_redirect_mode_the_shops_link_still_leads_to_tebexs_page(): void
    {
        $this->storeFor();
        $this->checkout();
        $payment = Payment::query()->sole();

        $this->get($this->continueUrl(Order::query()->sole()))->assertRedirect("https://pay.tebex.io/{$payment->provider_reference}");
    }

    // --- Popup and inline: the shop's own page ---------------------------------------------------------------------------

    #[Test]
    #[DataProvider('embeddedModes')]
    public function an_embedded_checkout_sends_the_buyer_to_the_shops_own_link_which_shows_the_payment_page(string $mode): void
    {
        config(['tebex.checkout.mode' => $mode]);
        $this->storeFor();

        $response = $this->checkout();

        $order = Order::query()->sole();
        $payment = Payment::query()->sole();
        $response->assertRedirect($this->continueUrl($order));
        $this->assertSame($this->continueUrl($order), $payment->checkout_url, 'coming back to it always works');

        $this->get($this->continueUrl($order))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('commerce/TebexPay')
            ->where('mode', $mode)
            ->where('ident', $payment->provider_reference)
            ->where('hostedUrl', "https://pay.tebex.io/{$payment->provider_reference}")
            ->where('completeUrl', $this->completeUrl($order))
            ->where('cancelUrl', route('shop.cart'))
            ->where('theme', 'default')
            ->where('order', ['number' => $order->number, 'total' => '19.98', 'currency' => 'USD']));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function embeddedModes(): array
    {
        return ['popup' => ['popup'], 'inline' => ['inline']];
    }

    #[Test]
    public function the_basket_is_filled_once_whatever_number_of_times_the_page_is_opened(): void
    {
        config(['tebex.checkout.mode' => 'popup']);
        $this->storeFor();
        $this->checkout();
        $order = Order::query()->sole();

        $this->get($this->continueUrl($order))->assertOk();
        $this->get($this->continueUrl($order))->assertOk();

        $this->assertCount(1, $this->tebex->calls('add'));
        $this->assertSame(2, $this->tebex->baskets[Payment::query()->sole()->provider_reference]['lines'][50001]['quantity']);
    }

    #[Test]
    public function a_store_that_asks_for_a_username_goes_straight_to_the_payment_page(): void
    {
        config(['tebex.checkout.mode' => 'inline']);
        $this->storeFor('Minecraft: Java Edition');

        $this->checkout(['game_username' => 'Notch'])->assertRedirect();

        $this->get($this->continueUrl(Order::query()->sole()))->assertOk()->assertInertia(fn (Assert $page) => $page->component('commerce/TebexPay')->where('mode', 'inline'));
        $this->assertSame([], $this->tebex->calls('auth'), 'nobody is asked to sign in');
    }

    #[Test]
    public function a_store_that_asks_the_buyer_to_sign_in_still_does_first_and_pays_on_the_shops_page_after(): void
    {
        config(['tebex.checkout.mode' => 'popup']);
        $this->storeFor('FiveM');
        $this->checkout()->assertRedirect();
        $order = Order::query()->sole();
        $payment = Payment::query()->sole();

        $this->get($this->continueUrl($order))->assertRedirect('https://auth.tebex.test/steam');

        $this->tebex->authorise($payment->provider_reference, 76561198000000042, 'ProGamer');
        $this->get($this->continueUrl($order))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('commerce/TebexPay')
            ->where('ident', $payment->provider_reference));
        $this->assertCount(1, $this->tebex->calls('add'));
        $this->assertSame(['username_id' => 76561198000000042], $this->tebex->calls('add')[0]['body']['variable_data']);
    }

    #[Test]
    public function the_buyer_of_a_store_that_asks_nothing_is_never_sent_to_sign_in(): void
    {
        config(['tebex.checkout.mode' => 'popup']);
        $this->storeFor();
        $this->checkout();

        // Not signed in at Tebex, and not meant to be.
        $this->get($this->continueUrl(Order::query()->sole()))->assertOk()->assertInertia(fn (Assert $page) => $page->component('commerce/TebexPay'));
        $this->assertSame([], $this->tebex->calls('auth'));
    }

    #[Test]
    public function the_theme_is_one_tebexjs_has_and_anything_else_is_the_stores(): void
    {
        config(['tebex.checkout.mode' => 'popup', 'tebex.checkout.theme' => ' Dark ']);
        $this->storeFor();
        $this->checkout();
        $order = Order::query()->sole();

        $this->get($this->continueUrl($order))->assertInertia(fn (Assert $page) => $page->where('theme', 'dark'));

        config(['tebex.checkout.theme' => 'neon']);
        $this->get($this->continueUrl($order))->assertInertia(fn (Assert $page) => $page->where('theme', 'default'));
    }

    // --- Nothing the page does settles anything ---------------------------------------------------------------------------

    #[Test]
    public function showing_the_payment_page_settles_nothing(): void
    {
        config(['tebex.checkout.mode' => 'popup']);
        $this->storeFor();
        $this->checkout();
        $order = Order::query()->sole();

        $this->get($this->continueUrl($order))->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame(OrderPaymentStatus::Unpaid, $order->payment_status);
    }

    #[Test]
    public function the_page_that_follows_a_payment_is_the_order_page_which_waits_for_the_webhook(): void
    {
        config(['tebex.checkout.mode' => 'popup']);
        $this->storeFor();
        $this->checkout();
        $order = Order::query()->sole();

        // What Tebex.js sends the buyer to when it says the payment is complete.
        $this->get($this->completeUrl($order))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('commerce/CheckoutComplete')
            ->where('order.status', 'pending')
            ->where('order.payment_status', 'unpaid'));
    }

    #[Test]
    public function a_basket_that_has_been_paid_goes_to_the_order_page_not_the_payment_page(): void
    {
        config(['tebex.checkout.mode' => 'popup']);
        $this->storeFor();
        $this->checkout();
        $order = Order::query()->sole();
        $this->tebex->markPaid(Payment::query()->sole()->provider_reference);

        $this->get($this->continueUrl($order))->assertRedirect($this->completeUrl($order));
    }

    #[Test]
    public function an_order_that_has_expired_or_is_cancelled_has_no_payment_page(): void
    {
        config(['tebex.checkout.mode' => 'inline']);
        $this->storeFor();
        $this->checkout();
        $order = Order::query()->sole();
        $order->forceFill(['status' => OrderStatus::Cancelled])->save();

        $this->get($this->continueUrl($order))->assertRedirect(route('shop.cart'))->assertSessionHas('error');
    }

    #[Test]
    public function an_address_to_pay_at_that_is_not_secure_is_not_offered_to_the_buyer(): void
    {
        Exceptions::fake();
        config(['tebex.checkout.mode' => 'popup']);
        $this->storeFor();
        $this->checkout();
        $this->tebex->checkoutBase = 'http://pay.tebex.test';

        $this->get($this->continueUrl(Order::query()->sole()))
            ->assertRedirect(route('shop.cart'))
            ->assertSessionHas('error', 'We could not start the payment. Please start the checkout again in a moment.');

        Exceptions::assertReported(fn (PaymentException $exception) => str_contains($exception->getMessage(), 'did not say where to pay'));
    }

    // --- The content security policy -------------------------------------------------------------------------------------------

    #[Test]
    public function tebexs_frame_is_let_in_only_while_the_checkout_is_embedded(): void
    {
        $this->assertStringNotContainsString('pay.tebex.io', (string) $this->get(route('home'))->headers->get('Content-Security-Policy'));

        foreach (['popup', 'inline'] as $mode) {
            config(['tebex.checkout.mode' => $mode]);

            $csp = (string) $this->get(route('home'))->headers->get('Content-Security-Policy');
            $this->assertMatchesRegularExpression('/frame-src [^;]*https:\/\/pay\.tebex\.io/', $csp, $mode);
            $this->assertStringContainsString("frame-src 'self' https://js.stripe.com", $csp, 'what was allowed before still is');
            $this->assertStringNotContainsString('script-src https://pay.tebex.io', $csp);
        }
    }

    #[Test]
    public function the_frames_allowed_are_the_ones_configured(): void
    {
        config(['tebex.checkout.mode' => 'popup', 'tebex.checkout.frame_sources' => ['https://pay.example.test', 42]]);

        $csp = (string) $this->get(route('home'))->headers->get('Content-Security-Policy');

        $this->assertMatchesRegularExpression('/frame-src [^;]*https:\/\/pay\.example\.test/', $csp);
        $this->assertStringNotContainsString('pay.tebex.io', $csp);
    }

    #[Test]
    public function the_checkout_page_says_whether_the_buyer_pays_on_the_shops_own_page(): void
    {
        $this->storeFor();

        $this->get(route('shop.checkout'))->assertInertia(fn (Assert $page) => $page->where('tebex', true)->where('tebexEmbedded', false));

        foreach (['popup', 'inline'] as $mode) {
            config(['tebex.checkout.mode' => $mode]);

            $this->get(route('shop.checkout'))->assertInertia(fn (Assert $page) => $page->where('tebexEmbedded', true), $mode);
        }
    }
}
