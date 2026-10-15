<?php

namespace Tests\Feature\Tebex;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Refund;
use App\Models\TebexPackage;
use App\Payments\Capability;
use App\Payments\Data\CheckoutClosure;
use App\Payments\Data\CheckoutContext;
use App\Payments\Exceptions\PaymentException;
use App\Payments\PaymentManager;
use App\Payments\Providers\TebexProvider;
use App\Payments\Tebex\TebexException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeTebexBaskets;
use Tests\Support\InteractsWithTebex;
use Tests\TestCase;

/**
 * The Tebex payment provider on its own: what it makes at Tebex for an order, how it closes a checkout, and what it
 * does not do yet.
 */
class TebexProviderTest extends TestCase
{
    use InteractsWithTebex;
    use RefreshDatabase;

    private FakeTebexBaskets $tebex;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTebex();
        $this->sellThroughTebex();

        $this->tebex = $this->fakeTebexBaskets();
    }

    private function provider(): TebexProvider
    {
        return app(PaymentManager::class)->provider('tebex') instanceof TebexProvider
            ? app(PaymentManager::class)->provider('tebex')
            : throw new \LogicException('The provider is not a Tebex one.');
    }

    private function context(): CheckoutContext
    {
        return new CheckoutContext(
            successUrl: 'https://shop.test/done',
            cancelUrl: 'https://shop.test/cart',
            expiresAt: now()->addHour(),
            ipAddress: '203.0.113.9',
        );
    }

    /**
     * An order with one line per package given, quantity two for the first.
     *
     * @param  list<TebexPackage>  $packages
     */
    private function orderOf(array $packages): Order
    {
        $order = Order::factory()->create(['payment_provider' => 'tebex', 'subtotal' => '20.00', 'grand_total' => '20.00']);

        foreach ($packages as $index => $package) {
            $order->items()->create([
                'tebex_package_id' => $package->id,
                'quantity' => $index === 0 ? 2 : 1,
                'unit_price' => $package->base_price,
                'subtotal' => $package->base_price,
                'description' => $package->name,
            ]);
        }

        return $order->refresh();
    }

    private function paymentFor(string $ident): Payment
    {
        return Payment::factory()->create([
            'order_id' => Order::factory(),
            'provider' => 'tebex',
            'provider_reference' => $ident,
            'status' => PaymentStatus::Pending,
        ]);
    }

    // --- What it is -----------------------------------------------------------------------------------------

    #[Test]
    public function it_is_a_one_time_hosted_checkout_that_cannot_refund_yet(): void
    {
        $provider = $this->provider();

        $this->assertSame('tebex', $provider->key());
        $this->assertSame('Tebex', $provider->label());
        $this->assertEqualsCanonicalizing([Capability::ONE_TIME_PAYMENTS, Capability::HOSTED_CHECKOUT], $provider->capabilities());
        $this->assertNotContains(Capability::REFUNDS, $provider->capabilities(), 'so the shop never asks it to');
    }

    #[Test]
    public function it_is_configured_when_there_is_a_public_token(): void
    {
        $this->assertTrue($this->provider()->isConfigured());

        config(['tebex.public_token' => null]);

        $this->assertFalse($this->provider()->isConfigured());
    }

    #[Test]
    public function it_is_not_on_offer_unless_the_preview_is_switched_on(): void
    {
        $registered = require config_path('commerce.php');

        $this->assertSame(['stripe'], array_keys($registered['providers']));

        $_ENV['TEBEX_CHECKOUT_PREVIEW'] = $_SERVER['TEBEX_CHECKOUT_PREVIEW'] = 'true';

        try {
            $withPreview = require config_path('commerce.php');
        } finally {
            unset($_ENV['TEBEX_CHECKOUT_PREVIEW'], $_SERVER['TEBEX_CHECKOUT_PREVIEW']);
        }

        $this->assertSame(['stripe', 'tebex'], array_keys($withPreview['providers']));
        $this->assertSame(TebexProvider::class, $withPreview['providers']['tebex']);
    }

    // --- Starting a checkout ----------------------------------------------------------------------------------

    #[Test]
    public function a_checkout_is_a_basket_of_the_orders_packages_and_where_to_pay(): void
    {
        $vip = TebexPackage::factory()->priced('10.00')->create(['ident' => 'vip-rank', 'name' => 'VIP']);
        $pet = TebexPackage::factory()->priced('5.00')->create(['ident' => 'pet-cat', 'name' => 'Pet cat']);
        $order = $this->orderOf([$vip, $pet]);

        $session = $this->provider()->startCheckout($order, $this->context());

        $this->assertSame('tebex', $session->provider);
        $this->assertStringStartsWith('bsk', $session->reference);
        $this->assertSame("https://pay.tebex.io/{$session->reference}", $session->redirectUrl);
        $this->assertSame(32, strlen($session->raw['nonce']));
        $this->assertEquals(['currency' => 'USD', 'base_price' => '25.00', 'sales_tax' => '0.00', 'total_price' => '25.00'], $session->raw['quoted']);
        $this->assertTrue($session->raw['price_differs'], 'the order says 20.00 and the basket 25.00');

        $custom = $this->tebex->calls('create')[0]['body']['custom'];
        $this->assertSame(['order' => $order->public_id, 'nonce' => $session->raw['nonce']], $custom);
        $this->assertSame([['package_id' => 'vip-rank', 'quantity' => 2], ['package_id' => 'pet-cat', 'quantity' => 1]], array_column($this->tebex->calls('add'), 'body'));
    }

    #[Test]
    public function every_checkout_has_a_nonce_of_its_own(): void
    {
        $order = $this->orderOf([TebexPackage::factory()->priced('10.00')->create(['ident' => 'vip-rank'])]);

        $first = $this->provider()->startCheckout($order, $this->context());
        $second = $this->provider()->startCheckout($order, $this->context());

        $this->assertNotSame($first->raw['nonce'], $second->raw['nonce']);
        $this->assertNotSame($first->reference, $second->reference);
    }

    #[Test]
    public function an_order_with_nothing_in_it_is_refused_without_asking_tebex(): void
    {
        $order = Order::factory()->create(['payment_provider' => 'tebex']);

        $this->expectException(PaymentException::class);

        try {
            $this->provider()->startCheckout($order, $this->context());
        } finally {
            $this->assertSame([], $this->tebex->requests);
        }
    }

    #[Test]
    public function a_line_that_is_not_a_package_is_refused_without_asking_tebex(): void
    {
        $order = Order::factory()->create(['payment_provider' => 'tebex']);
        $order->items()->create(['product_id' => Product::factory()->create()->id, 'quantity' => 1, 'unit_price' => '5.00', 'subtotal' => '5.00']);

        try {
            $this->provider()->startCheckout($order, $this->context());
            $this->fail('A product was put in a Tebex basket.');
        } catch (PaymentException $exception) {
            $this->assertStringContainsString('not a Tebex package', $exception->getMessage());
        }

        $this->assertSame([], $this->tebex->requests);
    }

    #[Test]
    public function a_package_with_no_ident_cannot_be_put_in_a_basket(): void
    {
        $order = $this->orderOf([TebexPackage::factory()->create(['ident' => null])]);

        $this->expectException(PaymentException::class);

        try {
            $this->provider()->startCheckout($order, $this->context());
        } finally {
            $this->assertSame([], $this->tebex->requests);
        }
    }

    #[Test]
    public function a_basket_with_nowhere_to_pay_is_not_a_checkout(): void
    {
        $order = $this->orderOf([TebexPackage::factory()->priced('10.00')->create(['ident' => 'vip-rank'])]);
        $this->fakeTebexHttp(['headless.tebex.io/*' => Http::response(['data' => ['id' => '1', 'ident' => 'bsk1', 'complete' => false, 'currency' => 'USD', 'base_price' => 20, 'sales_tax' => 0, 'total_price' => 20, 'packages' => [], 'links' => []]])]);

        try {
            $this->provider()->startCheckout($order, $this->context());
            $this->fail('A basket with no way to pay was accepted.');
        } catch (PaymentException $exception) {
            $this->assertStringContainsString('did not say where to pay', $exception->getMessage());
        }
    }

    // --- Closing a checkout --------------------------------------------------------------------------------------

    private function basketWithPackages(): string
    {
        $vip = TebexPackage::factory()->priced('10.00')->create(['tebex_id' => 50001, 'ident' => 'vip-rank']);
        $pet = TebexPackage::factory()->priced('5.00')->create(['tebex_id' => 50002, 'ident' => 'pet-cat']);

        return $this->provider()->startCheckout($this->orderOf([$vip, $pet]), $this->context())->reference;
    }

    #[Test]
    public function closing_an_unpaid_checkout_takes_everything_out_of_the_basket_and_says_it_is_closed(): void
    {
        $ident = $this->basketWithPackages();

        $closure = $this->provider()->closeCheckout($this->paymentFor($ident));

        $this->assertSame(CheckoutClosure::Closed, $closure);
        $this->assertSame([], $this->tebex->baskets[$ident]['lines']);
        $this->assertSame([['package_id' => '50001'], ['package_id' => '50002']], array_column($this->tebex->calls('remove'), 'body'));
    }

    #[Test]
    public function closing_a_paid_checkout_touches_nothing_and_says_it_is_not_settled(): void
    {
        $ident = $this->basketWithPackages();
        $this->tebex->markPaid($ident);

        $closure = $this->provider()->closeCheckout($this->paymentFor($ident));

        $this->assertSame(CheckoutClosure::Unresolved, $closure);
        $this->assertCount(2, $this->tebex->baskets[$ident]['lines']);
        $this->assertSame([], $this->tebex->calls('remove'));
    }

    #[Test]
    public function a_basket_that_will_not_empty_is_not_called_closed(): void
    {
        $ident = $this->basketWithPackages();
        $this->tebex->stubborn = true;

        $this->assertSame(CheckoutClosure::Unresolved, $this->provider()->closeCheckout($this->paymentFor($ident)));
    }

    #[Test]
    public function a_basket_paid_while_it_was_being_emptied_is_not_called_closed(): void
    {
        $ident = $this->basketWithPackages();
        // The customer pays at the moment the shop starts taking the packages out: Tebex does not wait for the shop.
        $paid = false;
        $this->fakeTebexHttp(['headless.tebex.io/*' => function ($request) use (&$paid, $ident) {
            if ($request->method() === 'POST' && ! $paid) {
                $paid = true;
                $this->tebex->markPaid($ident);
            }

            return $this->tebex->respond($request);
        }]);

        $this->assertSame(CheckoutClosure::Unresolved, $this->provider()->closeCheckout($this->paymentFor($ident)));
    }

    #[Test]
    public function when_tebex_cannot_be_asked_closing_is_an_error_not_an_answer(): void
    {
        $ident = $this->basketWithPackages();
        $this->tebex->failOn('get', 503, 4);

        $this->expectException(TebexException::class);

        $this->provider()->closeCheckout($this->paymentFor($ident));
    }

    #[Test]
    public function when_a_package_cannot_be_taken_out_closing_is_an_error_not_an_answer(): void
    {
        $ident = $this->basketWithPackages();
        $this->tebex->failOn('remove', 500);

        $this->expectException(TebexException::class);

        $this->provider()->closeCheckout($this->paymentFor($ident));
    }

    // --- What it does not do yet ----------------------------------------------------------------------------------

    #[Test]
    public function checking_on_a_payment_asks_tebex_nothing_because_only_its_webhook_settles_an_order(): void
    {
        $payment = $this->paymentFor('bsk000001');

        $this->provider()->reconcile($payment);
        $this->provider()->syncRefunds($payment);

        $this->assertSame([], $this->tebex->requests);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertNull($this->provider()->paymentUrl($payment));
    }

    #[Test]
    public function webhooks_and_refunds_are_not_handled_yet_and_say_so(): void
    {
        foreach ([
            fn () => $this->provider()->handleWebhook(Request::create('/webhooks/tebex', 'POST')),
            fn () => $this->provider()->refund(new Refund),
        ] as $call) {
            try {
                $call();
                $this->fail('Something that is not built answered.');
            } catch (PaymentException $exception) {
                $this->assertStringContainsString('yet', $exception->getMessage());
            }
        }
    }
}
