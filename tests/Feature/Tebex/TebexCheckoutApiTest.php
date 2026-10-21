<?php

namespace Tests\Feature\Tebex;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\BillingWebhookCall;
use App\Models\DownloadGrant;
use App\Models\GiftCard;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductFile;
use App\Models\TaxRate;
use App\Payments\Data\CheckoutContext;
use App\Payments\Exceptions\PaymentException;
use App\Payments\Providers\TebexProvider;
use App\Payments\Tebex\TebexCheckoutApi;
use App\Payments\Tebex\TebexCheckoutBasket;
use App\Payments\Tebex\TebexCheckoutClient;
use App\Payments\Tebex\TebexException;
use App\Payments\Tebex\TebexPaymentMatcher;
use App\Payments\Tebex\TebexSelling;
use App\Payments\Tebex\TebexStore;
use App\Payments\Tebex\TebexStoreProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeTebexCheckoutApi;
use Tests\Support\InteractsWithCommerce;
use Tests\Support\InteractsWithTebex;
use Tests\Support\InteractsWithTebexWebhooks;
use Tests\TestCase;

/**
 * Selling the shop's own products through Tebex's Checkout API (`TEBEX_CHECKOUT_API_ENABLED`), which is experimental: tested
 * against the shape of Tebex's documentation (`tests/Support/FakeTebexCheckoutApi`), never a live account. The shop prices the
 * order, Tebex adds the tax and takes the payment, and the webhook settles it once it is for exactly what was ordered.
 */
class TebexCheckoutApiTest extends TestCase
{
    use InteractsWithCommerce;
    use InteractsWithTebex;
    use InteractsWithTebexWebhooks;
    use RefreshDatabase;

    private FakeTebexCheckoutApi $api;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['commerce.downloads.disk' => 'local', 'commerce.downloads.limit' => 10, 'commerce.downloads.expires_after_days' => 0]);

        $this->setUpCommerce();
        $this->setUpTebex();
        $this->setUpTebexWebhooks();
        $this->sellThroughTebex();
        $this->useTebexCheckoutApi();
        Notification::fake();

        $this->api = $this->fakeTebexCheckoutApi();
    }

    private function product(string $price = '9.99', string $name = 'Ebook', bool $ships = false): Product
    {
        $product = Product::factory()->priced($price)->stocked(10)->create(['name' => $name, 'requires_shipping' => $ships]);

        return $product;
    }

    private function inCart(Product $product, int $quantity = 1): void
    {
        $this->addToCart($product, $quantity)->assertSessionHas('success');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function checkout(array $data = [])
    {
        return $this->post(route('shop.checkout.store'), ['email' => 'buyer@example.com', 'name' => 'Ada Buyer', 'token' => (string) Str::uuid(), ...$data]);
    }

    /**
     * Two products in the cart, checked out: a pending order of two lines.
     *
     * @return array{0: Order, 1: Payment}
     */
    private function placeTwoLineOrder(): array
    {
        $this->inCart($this->product('9.99', 'Ebook'), 2);
        $this->inCart($this->product('4.50', 'Poster pack'), 1);
        $this->checkout()->assertRedirect();

        $order = Order::query()->latest('id')->firstOrFail();

        return [$order, $order->payments()->firstOrFail()];
    }

    private function deliver(string $type, array $subject)
    {
        return $this->deliverToTebexEndpoint($this->tebexDelivery($type, $subject));
    }

    // --- The client ------------------------------------------------------------------------------------------------------------

    #[Test]
    public function the_checkout_request_is_made_the_way_tebex_documents_it(): void
    {
        $this->inCart($this->product('9.99', 'Ebook'), 2);
        $this->inCart($this->product('4.50', 'Poster pack'), 1);

        $this->checkout()->assertRedirect();

        $order = Order::query()->sole();
        $request = $this->api->calls('checkout')[0];

        $this->assertSame('POST', $request['method']);
        $this->assertSame('https://checkout.tebex.io/api/checkout', $request['url']);
        $this->assertSame('Basic '.base64_encode('123456:private-key-that-must-never-be-shown'), $request['headers']['Authorization'][0], 'the project id and the private key');

        $basket = $request['body']['basket'];
        $this->assertSame('Ada', $basket['first_name']);
        $this->assertSame('Buyer', $basket['last_name']);
        $this->assertSame('buyer@example.com', $basket['email']);
        $this->assertSame($order->public_id, $basket['custom']['order']);
        $this->assertSame(Payment::query()->sole()->raw['nonce'], $basket['custom']['nonce']);
        $this->assertStringContainsString('/checkout/', $basket['complete_url']);
        $this->assertSame(route('shop.cart'), $basket['return_url']);

        $items = $request['body']['items'];
        $this->assertCount(2, $items);

        foreach ($order->items->sortBy('id')->values() as $index => $line) {
            $item = $items[$index];

            $this->assertSame($line->quantity, $item['qty'], 'the quantity sits beside the package');
            $this->assertArrayNotHasKey('qty', $item['package'], 'inside it Tebex would ignore it');
            $this->assertSame('single', $item['package']['type']);
            $this->assertSame($line->description, $item['package']['name']);
            $this->assertSame((float) $line->unit_price, $item['package']['price']);
            $this->assertSame($line->id, $item['package']['custom']['line'], 'what the webhook is checked by');
            $this->assertSame($order->public_id, $item['package']['custom']['order']);
        }
    }

    #[Test]
    public function an_answer_shaped_like_the_documentation_is_read(): void
    {
        $basket = TebexCheckoutBasket::fromApi($this->tebexFixture('checkout-api-basket'), 'USD');

        $this->assertSame('ckt-8d2f9c1ab43e', $basket->ident);
        $this->assertFalse($basket->isPaid());
        $this->assertSame('19.98', $basket->subTotal->toDecimal());
        $this->assertSame('4.00', $basket->tax->toDecimal());
        $this->assertSame('23.98', $basket->total->toDecimal());
        $this->assertSame('https://pay.tebex.io/ckt-8d2f9c1ab43e', $basket->checkoutUrl);
        $this->assertNull($basket->paymentUrl);
        $this->assertSame([['id' => 5001, 'quantity' => 2, 'price' => '9.99', 'line' => 17]], $basket->rows);
    }

    #[Test]
    public function a_basket_that_is_paid_offers_a_payment_and_not_a_way_to_pay(): void
    {
        $data = $this->tebexFixture('checkout-api-basket');
        $data['links'] = ['payment' => 'https://pay.tebex.io/payments/ckt-8d2f9c1ab43e'];

        $basket = TebexCheckoutBasket::fromApi($data, 'USD');

        $this->assertTrue($basket->isPaid());
        $this->assertNull($basket->checkoutUrl);
    }

    #[Test]
    public function a_failure_says_what_to_check_and_never_shows_the_credentials(): void
    {
        $this->api->failOn('checkout', 401);

        try {
            app(TebexCheckoutClient::class)->checkout(['email' => 'a@b.test'], [], 'USD');
            $this->fail('A refusal was not reported.');
        } catch (TebexException $exception) {
            $this->assertTrue($exception->isAuthentication());
            $this->assertStringContainsString('approved the Checkout API', $exception->getMessage());
            $this->assertStringNotContainsString('private-key-that-must-never-be-shown', $exception->getMessage());
            $this->assertStringNotContainsString('123456', $exception->getMessage());
        }
    }

    #[Test]
    public function an_error_that_echoes_the_credentials_back_is_shown_without_them(): void
    {
        $this->api->failOn('checkout', 400, detail: 'The credentials 123456:private-key-that-must-never-be-shown are not valid');

        try {
            app(TebexCheckoutClient::class)->checkout(['email' => 'a@b.test'], [], 'USD');
            $this->fail('A refusal was not reported.');
        } catch (TebexException $exception) {
            $this->assertStringContainsString('are not valid', $exception->getMessage());
            $this->assertStringContainsString('[hidden]', $exception->getMessage());
            $this->assertStringNotContainsString('private-key-that-must-never-be-shown', $exception->getMessage());
            $this->assertStringNotContainsString('123456', $exception->getMessage());
        }
    }

    #[Test]
    public function making_a_basket_is_not_repeated_after_a_server_error_but_asking_for_one_is(): void
    {
        $this->api->failOn('checkout', 500, 3);

        try {
            app(TebexCheckoutClient::class)->checkout(['email' => 'a@b.test'], [], 'USD');
            $this->fail('A server error was not reported.');
        } catch (TebexException) {
            $this->assertCount(1, $this->api->calls('checkout'), 'it may have been made, and doing it twice would make two baskets');
        }

        $this->api->failOn('get', 500, 1);
        $this->api->baskets['ckt000009'] = ['ident' => 'ckt000009', 'complete' => false, 'complete_url' => null, 'cancel_url' => null, 'custom' => [], 'rows' => []];

        $basket = app(TebexCheckoutClient::class)->basket('ckt000009', 'USD');

        $this->assertSame('ckt000009', $basket->ident);
        $this->assertCount(2, $this->api->calls('get'));
    }

    #[Test]
    public function being_asked_to_slow_down_is_waited_out_for_any_call(): void
    {
        $this->api->failOn('checkout', 429, 1);

        $basket = app(TebexCheckoutClient::class)->checkout(['email' => 'a@b.test'], [['package' => ['name' => 'X', 'price' => 1.0, 'type' => 'single'], 'qty' => 1]], 'USD');

        $this->assertCount(2, $this->api->calls('checkout'), 'a 429 says it was not done');
        $this->assertNotSame('', $basket->ident);
    }

    #[Test]
    public function without_the_project_id_or_the_key_nothing_is_asked(): void
    {
        config(['tebex.checkout_api.project_id' => null]);

        $this->assertFalse(app(TebexCheckoutClient::class)->isConfigured());
        $this->assertFalse(app(TebexProvider::class)->isConfigured());

        try {
            app(TebexCheckoutClient::class)->basket('ckt000001', 'USD');
            $this->fail('A call went out with no project id.');
        } catch (TebexException $exception) {
            $this->assertStringContainsString('TEBEX_PROJECT_ID', $exception->getMessage());
        }

        $this->assertSame([], $this->api->requests);
    }

    #[Test]
    public function the_provider_needs_the_project_id_the_key_and_the_webhook_secret_but_not_the_public_token(): void
    {
        $this->assertNull(config('tebex.public_token'));
        $this->assertTrue(app(TebexProvider::class)->isConfigured());

        config(['tebex.webhook.secret' => null]);
        $this->assertFalse(app(TebexProvider::class)->isConfigured(), 'a payment could never be applied without the secret');

        config(['tebex.webhook.secret' => 'whsec-'.str_repeat('b', 24), 'tebex.private_key' => null]);
        $this->assertFalse(app(TebexProvider::class)->isConfigured());
    }

    // --- What the shop sells ----------------------------------------------------------------------------------------------------

    #[Test]
    public function the_shop_sells_its_own_products_and_the_packages_shop_is_off(): void
    {
        $this->assertTrue(TebexSelling::products('tebex'));
        $this->assertFalse(TebexSelling::packages('tebex'));
        $this->assertFalse(TebexSelling::products('stripe'));

        $this->get(route('shop.index'))->assertOk();
        $this->get(route('shop.packages'))->assertNotFound();
        $this->post(route('shop.cart.packages.store'), ['package_id' => 1, 'quantity' => 1])->assertNotFound();
    }

    #[Test]
    public function with_the_flag_off_nothing_changes_and_the_shop_sells_packages(): void
    {
        config(['tebex.checkout_api.enabled' => false]);

        $this->assertTrue(TebexSelling::packages('tebex'));
        $this->assertFalse(TebexSelling::products('tebex'));

        $this->get(route('shop.index'))->assertRedirect(route('shop.packages'));
        $this->post(route('shop.cart.items.store'), ['product_id' => $this->product()->id, 'quantity' => 1])->assertSessionHas('error', 'This shop sells packages, not products.');
    }

    #[Test]
    public function a_product_that_has_to_be_shipped_cannot_be_added(): void
    {
        $this->addToCart($this->product(ships: true))->assertSessionHas('error', fn (string $message) => str_contains($message, 'cannot ship goods'));

        $this->assertSame(0, $this->cartItemCount());
    }

    #[Test]
    public function a_cart_of_products_is_paid_through_tebex_so_no_code_gift_card_tax_or_address_comes_into_it(): void
    {
        TaxRate::factory()->create(['country' => 'US']);
        $this->inCart($this->product('20.00'), 1);

        $this->post(route('shop.cart.coupon.store'), ['code' => 'SAVE10'])->assertSessionHasErrors(['code' => "Tebex takes discount codes when you pay, so the shop's own codes are not used with what is paid for through Tebex."]);

        $card = GiftCard::factory()->create();
        $this->post(route('shop.cart.gift-card.store'), ['gift_card' => 'whatever'])->assertSessionHasErrors('gift_card');
        $this->assertNotNull($card);

        $this->get(route('shop.cart'))->assertInertia(fn (Assert $page) => $page->where('tebex', true)->where('giftCardsEnabled', false)->where('coupon', null));
        $this->get(route('shop.checkout'))->assertInertia(fn (Assert $page) => $page->where('tebex', true)->where('billingRequired', false));

        $this->checkout()->assertRedirect();

        $order = Order::query()->sole();
        $this->assertSame('20.00', $order->grand_total, 'the price before tax, which Tebex adds');
        $this->assertSame('0.00', $order->tax_total);
        $this->assertSame('0.00', $order->shipping_total);
        $this->assertSame('0.00', $order->discount_total);
        $this->assertNull($order->shipping_address ?? null);
    }

    #[Test]
    public function a_buyer_of_the_shops_products_is_not_asked_for_a_game_username_whatever_the_store_was_before(): void
    {
        // What the shop learned about the store while it sold Tebex's packages for a Minecraft store.
        app(TebexStoreProfile::class)->remember(TebexStore::fromApi($this->tebexStoreOn('Minecraft: Java Edition')['data']));
        $this->assertSame(TebexStoreProfile::USERNAME, app(TebexStoreProfile::class)->identity());

        $this->inCart($this->product(), 1);

        $this->get(route('shop.checkout'))->assertInertia(fn (Assert $page) => $page->where('tebex', true)->where('identity.kind', TebexStoreProfile::NONE));

        // And is not required to give one.
        $this->checkout()->assertRedirect('https://pay.tebex.io/ckt000001');
    }

    #[Test]
    public function a_cart_that_already_held_something_to_ship_is_refused_at_checkout(): void
    {
        // Put there before the shop sold through Tebex (or by switching the flag on).
        $this->cartWith($this->product(ships: true));

        $this->checkout()->assertSessionHas('error', fn (string $message) => str_contains($message, 'cannot be paid for through Tebex') || str_contains($message, 'has to be shipped'));

        $this->assertSame(0, Order::query()->count());
        $this->assertSame([], $this->api->requests);
    }

    // --- Checking out ------------------------------------------------------------------------------------------------------------

    #[Test]
    public function checking_out_makes_a_pending_order_and_a_basket_and_sends_the_customer_to_tebex(): void
    {
        $this->inCart($this->product('9.99', 'Ebook'), 2);

        $response = $this->checkout();

        $order = Order::query()->sole();
        $payment = Payment::query()->sole();

        $response->assertRedirect("https://pay.tebex.io/{$payment->provider_reference}");
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame(OrderPaymentStatus::Unpaid, $order->payment_status);
        $this->assertSame('tebex', $order->payment_provider);
        $this->assertSame('19.98', $order->grand_total);
        $this->assertSame('ckt000001', $payment->provider_reference);
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame('19.98', $payment->amount);
        $this->assertSame('checkout_api', $payment->raw['mode']);
        $this->assertSame(32, strlen($payment->raw['nonce']));
        $this->assertFalse($payment->raw['price_differs']);
        $this->assertSame('19.98', $payment->raw['quoted']['base_price']);
        $this->assertNotNull($order->items->first()->product_id);
        $this->assertNull($order->items->first()->tebex_package_id);
    }

    #[Test]
    public function a_price_tebex_quotes_differently_is_noted_on_the_payment(): void
    {
        $this->inCart($this->product('9.99'), 2);
        // Tebex rounds in its own way, or its price for the item moved: the buyer pays what Tebex charges, and whoever
        // reconciles the payment is told.
        $this->api->extraPerUnit = 0.01;
        Log::spy();

        $this->checkout()->assertRedirect('https://pay.tebex.io/ckt000001');

        $payment = Payment::query()->sole();
        $this->assertTrue($payment->raw['price_differs']);
        $this->assertSame('20.00', $payment->raw['quoted']['base_price']);
        $this->assertSame('19.98', Order::query()->sole()->subtotal, 'the order carries the shop\'s quote');
        Log::shouldHaveReceived('warning')->once();
    }

    #[Test]
    public function a_basket_with_no_place_to_pay_is_sent_to_the_documented_address(): void
    {
        $this->api->withoutCheckoutLink = true;
        $this->inCart($this->product(), 1);

        $this->checkout()->assertRedirect('https://pay.tebex.io/ckt000001');
    }

    #[Test]
    public function a_place_to_pay_that_is_not_secure_cancels_the_checkout(): void
    {
        $this->api->checkoutBase = 'http://pay.tebex.test';
        $this->inCart($this->product(), 1);

        $this->checkout()->assertSessionHas('error');

        $this->assertSame(OrderStatus::Cancelled, Order::query()->sole()->status);
        $this->assertSame(0, Payment::query()->count());
    }

    #[Test]
    public function a_failure_at_tebex_cancels_the_order_and_sends_the_customer_nowhere(): void
    {
        $this->api->failOn('checkout', 403);
        $this->inCart($this->product(), 1);

        $this->checkout()->assertSessionHas('error', 'We could not start the payment. Please try again in a moment.');

        $this->assertSame(OrderStatus::Cancelled, Order::query()->sole()->status);
        $this->assertSame(0, Payment::query()->count());
    }

    #[Test]
    public function an_embedded_checkout_is_paid_on_a_page_of_the_shops_with_tebex_js(): void
    {
        config(['tebex.checkout.mode' => 'popup']);
        $this->inCart($this->product(), 1);

        $response = $this->checkout();

        $order = Order::query()->sole();
        $continue = URL::signedRoute('shop.tebex.continue', ['order' => $order->public_id]);
        $response->assertRedirect($continue);

        $this->get($continue)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('commerce/TebexPay')
            ->where('mode', 'popup')
            ->where('ident', 'ckt000001')
            ->where('hostedUrl', 'https://pay.tebex.io/ckt000001'));

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'headless.tebex.io'));
    }

    #[Test]
    public function coming_back_to_the_link_after_paying_goes_to_the_order_and_changes_nothing(): void
    {
        config(['tebex.checkout.mode' => 'popup']);
        $this->inCart($this->product(), 1);
        $this->checkout();
        $order = Order::query()->sole();
        $this->api->markPaid('ckt000001');

        $this->get(URL::signedRoute('shop.tebex.continue', ['order' => $order->public_id]))
            ->assertRedirect(URL::signedRoute('shop.checkout.complete', ['order' => $order->public_id]));

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status, 'only the webhook settles an order');
    }

    #[Test]
    public function checking_out_again_closes_the_earlier_basket_at_tebex(): void
    {
        $this->inCart($this->product('9.99'), 1);
        $this->checkout()->assertRedirect();
        $first = Order::query()->sole();

        $this->checkout()->assertRedirect('https://pay.tebex.io/ckt000002');

        $this->assertCount(1, $this->api->calls('remove'), 'the earlier basket was emptied so it cannot be paid');
        $this->assertSame([], $this->api->baskets['ckt000001']['rows']);
        $this->assertSame(OrderStatus::Cancelled, $first->fresh()->status);
        $this->assertSame(1, Order::query()->where('status', OrderStatus::Pending->value)->count());
    }

    #[Test]
    public function an_earlier_basket_that_tebex_will_not_empty_is_not_replaced(): void
    {
        $this->inCart($this->product('9.99'), 1);
        $this->checkout()->assertRedirect();
        $this->api->stubborn = true;

        $this->checkout()->assertSessionHas('error');

        $this->assertSame(1, Order::query()->count(), 'a basket that could still be paid would sit beside its replacement');
        $this->assertSame(OrderStatus::Pending, Order::query()->sole()->status);
    }

    #[Test]
    public function a_basket_that_was_paid_in_the_meantime_is_left_alone(): void
    {
        $this->inCart($this->product('9.99'), 1);
        $this->checkout()->assertRedirect();
        $this->api->markPaid('ckt000001');

        $this->checkout()->assertSessionHas('error');

        $this->assertCount(0, $this->api->calls('remove'));
        $this->assertSame(OrderStatus::Pending, Order::query()->sole()->status, 'the webhook has not said so yet');
    }

    // --- Settling the order ------------------------------------------------------------------------------------------------------------

    #[Test]
    public function a_payment_for_exactly_what_was_ordered_settles_the_order(): void
    {
        [$order, $payment] = $this->placeTwoLineOrder();

        $this->deliver('payment.completed', $this->tebexProductPaymentFor($order, $payment))->assertOk();

        $this->assertSame(OrderPaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()->status);
        $this->assertSame('tbx-4a1b2c3d4e5f', $payment->fresh()->provider_payment_id);
    }

    #[Test]
    public function the_tax_tebex_adds_is_recorded_on_the_order(): void
    {
        [$order, $payment] = $this->placeTwoLineOrder();
        $paid = (float) $order->grand_total + 4.5;

        $this->deliver('payment.completed', $this->tebexProductPaymentFor($order, $payment, ['price_paid' => ['amount' => $paid, 'currency' => 'USD']]))->assertOk();

        $order->refresh();
        $this->assertSame(OrderPaymentStatus::Paid, $order->payment_status);
        $this->assertSame('4.50', $order->tax_total);
        $this->assertSame(number_format($paid, 2, '.', ''), $order->grand_total);
    }

    #[Test]
    public function a_product_with_files_is_delivered_by_the_shop_and_completes_the_order(): void
    {
        $product = $this->product('15.00', 'Ebook');
        ProductFile::factory()->for($product)->withContents('the file bytes')->create();
        $this->inCart($product, 1);
        $this->checkout()->assertRedirect();
        $order = Order::query()->sole();
        $payment = $order->payments()->sole();

        $this->deliver('payment.completed', $this->tebexProductPaymentFor($order, $payment))->assertOk();

        $this->assertSame(1, DownloadGrant::query()->where('order_id', $order->id)->count());
        $this->assertSame(OrderStatus::Completed, $order->fresh()->status, 'every line was delivered by its files');
    }

    #[Test]
    public function a_product_nothing_delivers_is_left_for_staff_as_it_is_for_any_paid_order(): void
    {
        $this->inCart($this->product('15.00', 'Service'), 1);
        $this->checkout()->assertRedirect();
        $order = Order::query()->sole();

        $this->deliver('payment.completed', $this->tebexProductPaymentFor($order, $order->payments()->sole()))->assertOk();

        $this->assertSame(OrderPaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertSame(OrderStatus::Processing, $order->fresh()->status, 'Tebex delivers its own packages, not the shop\'s products');
    }

    #[Test]
    public function a_payment_that_is_not_for_what_was_ordered_is_held_for_a_person(): void
    {
        [$order, $payment] = $this->placeTwoLineOrder();
        [$ebook, $poster] = $order->items->sortBy('id')->values()->all();

        $subject = fn (callable $change) => $change($this->tebexProductPaymentFor($order, $payment));

        $cases = [
            'one fewer of a line' => $subject(function (array $s) {
                $s['products'][0]['quantity'] = 1;

                return $s;
            }),
            'more of a line' => $subject(function (array $s) {
                $s['products'][1]['quantity'] = 5;

                return $s;
            }),
            'a line left out' => $subject(function (array $s) {
                array_pop($s['products']);

                return $s;
            }),
            'a line the order does not hold' => $subject(function (array $s) {
                $s['products'][] = [...$s['products'][0], 'custom' => [...$s['products'][0]['custom'], 'line' => 999999]];

                return $s;
            }),
            'a product that says nothing of which line it is' => $subject(function (array $s) {
                unset($s['products'][1]['custom']['line']);

                return $s;
            }),
            'a line twice' => $subject(function (array $s) {
                $s['products'][] = $s['products'][0];

                return $s;
            }),
            'a different price' => $subject(function (array $s) {
                $s['products'][0]['base_price']['amount'] = 0.01;

                return $s;
            }),
            'no products at all' => $subject(function (array $s) {
                $s['products'] = [];

                return $s;
            }),
        ];

        foreach ($cases as $name => $delivery) {
            $payment->forceFill(['status' => PaymentStatus::Pending, 'provider_payment_id' => null])->save();

            $this->deliver('payment.completed', $delivery)->assertOk();

            $this->assertSame(OrderPaymentStatus::Unpaid, $order->fresh()->payment_status, $name);
            $this->assertSame(PaymentStatus::Review, $payment->fresh()->status, $name);
        }

        $this->assertNotNull($ebook);
        $this->assertNotNull($poster);
    }

    #[Test]
    public function a_payment_whose_products_do_not_say_which_line_they_are_for_is_held_and_says_so(): void
    {
        $this->inCart($this->product('15.00'), 1);
        $this->checkout()->assertRedirect();
        $order = Order::query()->sole();
        $payment = $order->payments()->sole();
        $subject = $this->tebexProductPaymentFor($order, $payment);
        unset($subject['products'][0]['custom']['line']);

        $this->deliver('payment.completed', $subject)->assertOk();

        $this->assertSame(PaymentStatus::Review, $payment->fresh()->status);
        $this->assertStringContainsString('does not say which of the order\'s lines each item is for', (string) BillingWebhookCall::query()->where('type', 'payment.completed')->sole()->error);
    }

    #[Test]
    public function a_basket_is_not_made_for_an_order_that_holds_something_the_checkout_api_cannot_sell(): void
    {
        $api = app(TebexCheckoutApi::class);
        $context = new CheckoutContext(route('shop.cart'), route('shop.cart'), now()->addHour());

        $shipped = Order::factory()->create();
        $shipped->items()->create(['product_id' => $this->product()->id, 'description' => 'Poster', 'quantity' => 1, 'unit_price' => '5.00', 'subtotal' => '5.00', 'requires_shipping' => true]);

        $package = Order::factory()->create();
        $package->items()->create(['description' => 'VIP rank', 'quantity' => 1, 'unit_price' => '5.00', 'subtotal' => '5.00']);

        $empty = Order::factory()->create();

        foreach ([[$shipped, 'has to be shipped'], [$package, 'not one of the shop\'s products'], [$empty, 'nothing in it to sell']] as [$order, $why]) {
            try {
                $api->start($order, $context);
                $this->fail("A basket was made for an order with {$why}.");
            } catch (PaymentException $exception) {
                $this->assertStringContainsString($why, $exception->getMessage());
            }
        }

        $this->assertSame([], $this->api->requests, 'nothing was sent to Tebex');
    }

    #[Test]
    public function a_price_may_be_reported_for_one_unit_or_for_the_whole_row(): void
    {
        [$order, $payment] = $this->placeTwoLineOrder();
        $subject = $this->tebexProductPaymentFor($order, $payment);

        // Tebex's documentation does not say which `base_price` is; the ebook was ordered twice at 9.99.
        $subject['products'][0]['base_price']['amount'] = 19.98;

        $this->deliver('payment.completed', $subject)->assertOk();

        $this->assertSame(OrderPaymentStatus::Paid, $order->fresh()->payment_status);
    }

    #[Test]
    public function a_payment_whose_products_report_no_usable_price_is_held_not_settled(): void
    {
        [$order, $payment] = $this->placeTwoLineOrder();

        // The total paid may differ from the order's (Tebex adds the tax), so the price of each line is what says the
        // basket was not changed: naming the right lines and leaving the price out must not settle the order.
        $cases = [
            'no price on any product' => [function (array $s) {
                unset($s['products'][0]['base_price'], $s['products'][1]['base_price']);

                return $s;
            }, 'no readable price'],
            'no price on one product' => [function (array $s) {
                unset($s['products'][1]['base_price']);

                return $s;
            }, 'no readable price'],
            'an amount that is not a number' => [function (array $s) {
                $s['products'][0]['base_price']['amount'] = 'free';

                return $s;
            }, 'no readable price'],
            'no amount' => [function (array $s) {
                unset($s['products'][0]['base_price']['amount']);

                return $s;
            }, 'no readable price'],
            'no currency' => [function (array $s) {
                unset($s['products'][0]['base_price']['currency']);

                return $s;
            }, 'no readable price'],
            'a currency that is not the order\'s' => [function (array $s) {
                $s['products'][0]['base_price']['currency'] = 'EUR';

                return $s;
            }, 'priced in EUR, not USD'],
        ];

        foreach ($cases as $name => [$change, $why]) {
            $payment->forceFill(['status' => PaymentStatus::Pending, 'provider_payment_id' => null])->save();

            $this->deliver('payment.completed', $change($this->tebexProductPaymentFor($order, $payment)))->assertOk();

            $this->assertSame(OrderPaymentStatus::Unpaid, $order->fresh()->payment_status, $name);
            $this->assertSame(PaymentStatus::Review, $payment->fresh()->status, $name);
            $this->assertStringContainsString($why, (string) BillingWebhookCall::query()->latest('id')->first()->error, $name);
        }
    }

    #[Test]
    public function the_cart_page_offers_checkout_for_free_products_even_when_tebex_is_not_set_up(): void
    {
        config(['tebex.webhook.secret' => null]);
        $this->assertFalse(app(TebexProvider::class)->isConfigured());

        $this->inCart($this->product('0.00', 'Free sample'), 1);

        // Nothing to pay needs no provider, here as for any other.
        $this->get(route('shop.cart'))->assertInertia(fn (Assert $page) => $page
            ->where('tebex', true)
            ->where('coupon', null)
            ->where('giftCardsEnabled', false)
            ->where('checkoutAvailable', true));
    }

    #[Test]
    public function the_cart_page_does_not_offer_checkout_for_products_that_cost_something_while_tebex_is_not_set_up(): void
    {
        config(['tebex.webhook.secret' => null]);

        $this->inCart($this->product('4.50'), 1);

        $this->get(route('shop.cart'))->assertInertia(fn (Assert $page) => $page->where('tebex', true)->where('checkoutAvailable', false));
    }

    #[Test]
    public function free_products_are_paid_on_the_spot_without_asking_tebex(): void
    {
        config(['tebex.webhook.secret' => null]);
        $this->inCart($this->product('0.00', 'Free sample'), 1);

        $this->checkout()->assertRedirect();

        $order = Order::query()->sole();
        $this->assertSame(OrderPaymentStatus::Paid, $order->payment_status);
        $this->assertSame('0.00', $order->grand_total);
        $this->assertSame([], $this->api->requests, 'no basket is made for an order that costs nothing');
        $this->assertSame(0, Payment::query()->count());
    }

    #[Test]
    public function the_held_payment_says_what_was_wrong(): void
    {
        [$order, $payment] = $this->placeTwoLineOrder();
        $subject = $this->tebexProductPaymentFor($order, $payment);
        $subject['products'][0]['quantity'] = 1;

        $this->deliver('payment.completed', $subject)->assertOk();

        $call = BillingWebhookCall::query()->where('type', 'payment.completed')->sole();
        $this->assertStringContainsString('items that are not what order', (string) $call->error);
        $this->assertStringContainsString('1 bought, 2 ordered', (string) $call->error);

        $first = $order->items->sortBy('id')->first();
        $kept = $payment->fresh()->raw['purchased_lines'][$first->id];
        $this->assertSame(1, $kept['quantity'], 'what Tebex listed is kept, for whoever decides what to do');
        $this->assertSame('9.99', $kept['price']);
    }

    #[Test]
    public function a_refund_at_tebex_takes_the_order_back_and_ends_the_download(): void
    {
        $product = $this->product('15.00', 'Ebook');
        ProductFile::factory()->for($product)->withContents('the file bytes')->create();
        $this->inCart($product, 1);
        $this->checkout()->assertRedirect();
        $order = Order::query()->sole();
        $payment = $order->payments()->sole();
        $this->deliver('payment.completed', $this->tebexProductPaymentFor($order, $payment))->assertOk();

        $this->deliver('payment.refunded', $this->tebexProductPaymentFor($order, $payment, ['status' => ['id' => 2, 'description' => 'Refund']]))->assertOk();

        $this->assertSame(OrderPaymentStatus::Refunded, $order->fresh()->payment_status);
        $this->assertNotNull(DownloadGrant::query()->where('order_id', $order->id)->sole()->revoked_at);
    }

    #[Test]
    public function an_order_is_told_to_be_of_products_or_of_packages_by_its_lines(): void
    {
        $matcher = app(TebexPaymentMatcher::class);

        $packages = Order::factory()->create();
        $packages->items()->create(['description' => 'VIP rank', 'quantity' => 1, 'unit_price' => '1.00', 'subtotal' => '1.00']);

        [$products] = $this->placeTwoLineOrder();

        $this->assertFalse($matcher->soldAsProducts($packages), 'a line that is no product of the shop is a package');
        $this->assertTrue($matcher->soldAsProducts($products));
    }
}
