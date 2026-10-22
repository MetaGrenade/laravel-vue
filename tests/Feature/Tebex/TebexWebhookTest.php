<?php

namespace Tests\Feature\Tebex;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\BillingWebhookCall;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\TebexPackage;
use App\Models\User;
use App\Payments\Tebex\TebexWebhookVerifier;
use App\Support\Commerce\CartManager;
use App\Support\Commerce\OrderLifecycle;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\FakeTebexBaskets;
use Tests\Support\InteractsWithCommerce;
use Tests\Support\InteractsWithTebex;
use Tests\Support\InteractsWithTebexWebhooks;
use Tests\TestCase;

/**
 * What Tebex tells the shop: that a basket was paid, declined or refunded, and the disputes that follow. The deliveries
 * are shaped like the ones Tebex's documentation shows (`tests/Fixtures/tebex/payment.json`); that the real ones are the
 * same is the live check's job (M2b).
 */
class TebexWebhookTest extends TestCase
{
    use InteractsWithCommerce;
    use InteractsWithTebex;
    use InteractsWithTebexWebhooks;
    use RefreshDatabase;

    private const TRANSACTION = 'tbx-4a1b2c3d4e5f';

    private FakeTebexBaskets $tebex;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerce();
        $this->setUpTebex();
        $this->setUpTebexWebhooks();
        $this->sellThroughTebex();
        Notification::fake();

        $this->tebex = $this->fakeTebexBaskets();
    }

    /**
     * Check out a cart of one package through the real checkout, as a buyer would.
     *
     * @return array{0: Order, 1: Payment}
     */
    private function placeOrder(int $quantity = 1, string $price = '10.00'): array
    {
        $package = TebexPackage::factory()->priced($price)->create(['ident' => 'vip-rank', 'name' => 'Vip Rank']);

        $this->post(route('shop.cart.packages.store'), ['package_id' => $package->tebex_id, 'quantity' => $quantity])->assertSessionHas('success');
        $this->post(route('shop.checkout.store'), ['email' => 'buyer@example.com', 'name' => 'Ada Buyer', 'token' => (string) Str::uuid()])->assertRedirect();

        $order = Order::query()->latest('id')->firstOrFail();

        return [$order, $order->payments()->firstOrFail()];
    }

    /**
     * Settle an order the way Tebex does, and return it fresh.
     *
     * @param  array<string, mixed>  $subject
     * @return array{0: Order, 1: Payment}
     */
    private function placeAndPay(int $quantity = 1, string $price = '10.00', array $subject = []): array
    {
        [$order, $payment] = $this->placeOrder($quantity, $price);

        $this->deliverToTebexEndpoint($this->tebexDelivery('payment.completed', $this->tebexPaymentFor($order, $payment, $subject)))->assertOk();

        return [$order->fresh(), $payment->fresh()];
    }

    /**
     * @param  array<string, mixed>  $subject
     */
    private function deliver(string $type, array $subject, ?string $id = null)
    {
        return $this->deliverToTebexEndpoint($this->tebexDelivery($type, $subject, $id));
    }

    // --- Who may send ----------------------------------------------------------------------------------

    #[Test]
    public function only_tebexs_addresses_are_answered(): void
    {
        [$order, $payment] = $this->placeOrder();
        $delivery = $this->tebexDelivery('payment.completed', $this->tebexPaymentFor($order, $payment));

        $this->deliverToTebexEndpoint($delivery, ip: '203.0.113.9')->assertNotFound();
        $this->deliverToTebexEndpoint($delivery, ip: '54.87.231.232')->assertOk();

        $this->assertSame(OrderPaymentStatus::Paid, $order->fresh()->payment_status);
    }

    #[Test]
    public function the_address_check_can_be_switched_off_for_a_proxy_that_hides_it(): void
    {
        config(['tebex.webhook.check_ip' => false]);

        $this->deliverToTebexEndpoint($this->tebexDelivery('basket.abandoned', []), ip: '203.0.113.9')->assertOk();
    }

    #[Test]
    public function nothing_is_taken_without_a_secret(): void
    {
        config(['tebex.webhook.secret' => null]);

        $this->deliverToTebexEndpoint($this->tebexDelivery('basket.abandoned', []))->assertStatus(500);
        $this->assertSame(0, BillingWebhookCall::query()->count());
    }

    #[Test]
    public function a_delivery_without_a_valid_signature_is_refused_and_kept_nowhere(): void
    {
        [$order, $payment] = $this->placeOrder();
        $body = (string) json_encode($this->tebexDelivery('payment.completed', $this->tebexPaymentFor($order, $payment)));

        $this->deliverToTebexEndpoint($body, signature: 'not-the-signature')->assertStatus(400);
        $this->deliverToTebexEndpoint($body, signature: TebexWebhookVerifier::sign($body, 'some-other-secret'))->assertStatus(400);
        $this->deliverToTebexEndpoint($body, signature: TebexWebhookVerifier::sign('{}', $this->tebexWebhookSecret))->assertStatus(400);

        $this->assertSame(OrderPaymentStatus::Unpaid, $order->fresh()->payment_status);
        $this->assertSame(0, BillingWebhookCall::query()->count());
    }

    #[Test]
    public function a_delivery_with_no_signature_header_is_refused(): void
    {
        $body = (string) json_encode($this->tebexDelivery('basket.abandoned', []));

        $this->call('POST', route('tebex.webhook'), [], [], [], ['REMOTE_ADDR' => '18.209.80.3', 'CONTENT_TYPE' => 'application/json'], $body)->assertStatus(400);
    }

    #[Test]
    public function the_signature_is_of_the_body_as_it_was_sent_not_of_what_it_parses_to(): void
    {
        $delivery = $this->tebexDelivery('basket.abandoned', []);
        $compact = (string) json_encode($delivery);
        $pretty = (string) json_encode($delivery, JSON_PRETTY_PRINT);

        $this->deliverToTebexEndpoint($pretty, signature: TebexWebhookVerifier::sign($compact, $this->tebexWebhookSecret))->assertStatus(400);
        $this->deliverToTebexEndpoint($pretty, signature: TebexWebhookVerifier::sign($pretty, $this->tebexWebhookSecret))->assertOk();
    }

    #[Test]
    public function the_signature_is_the_hmac_of_the_bodys_sha256_keyed_with_the_secret(): void
    {
        $this->assertSame(hash_hmac('sha256', hash('sha256', '{"a":1}'), 'secret'), TebexWebhookVerifier::sign('{"a":1}', 'secret'));
    }

    #[Test]
    public function a_signed_body_that_is_not_a_delivery_is_refused(): void
    {
        foreach (['not json', '[]', '{"type":"payment.completed","subject":{}}', '{"id":"x","subject":{}}', '{"id":"x","type":"payment.completed","subject":"no"}'] as $body) {
            $this->deliverToTebexEndpoint($body)->assertStatus(400);
        }

        $this->assertSame(0, BillingWebhookCall::query()->count());
    }

    #[Test]
    public function the_endpoint_only_takes_posts(): void
    {
        $this->get(route('tebex.webhook'))->assertStatus(405);
    }

    #[Test]
    public function the_validation_delivery_is_answered_with_its_own_id_and_kept_nowhere(): void
    {
        $this->deliverToTebexEndpoint(['id' => '2c116b11-1110-91e0-b266-b792c8da5f11', 'type' => 'validation.webhook', 'date' => '2021-08-24T12:21:47+00:00', 'subject' => []])
            ->assertOk()
            ->assertExactJson(['id' => '2c116b11-1110-91e0-b266-b792c8da5f11']);

        $this->assertSame(0, BillingWebhookCall::query()->count());
    }

    #[Test]
    public function the_validation_delivery_must_be_signed_too(): void
    {
        $this->deliverToTebexEndpoint(['id' => 'abc', 'type' => 'validation.webhook', 'date' => null, 'subject' => []], signature: 'nope')->assertStatus(400);
    }

    #[Test]
    public function a_type_the_shop_does_not_act_on_is_kept_and_acknowledged(): void
    {
        $this->deliver('basket.abandoned', ['basket' => 'bsk-1'], 'evt-1')->assertOk();

        $call = BillingWebhookCall::query()->sole();
        $this->assertSame('tebex', $call->provider);
        $this->assertSame('evt-1', $call->external_id);
        $this->assertSame('basket.abandoned', $call->type);
        $this->assertNotNull($call->processed_at);
        $this->assertNull($call->error);
    }

    // --- A payment ---------------------------------------------------------------------------------------

    #[Test]
    public function a_completed_payment_settles_the_order_and_tebex_delivers_it(): void
    {
        [$order, $payment] = $this->placeAndPay(quantity: 2, price: '10.00');

        $this->assertSame(OrderStatus::Completed, $order->status, 'Tebex delivers the package itself, so there is nothing left to do');
        $this->assertSame(OrderPaymentStatus::Paid, $order->payment_status);
        $this->assertNotNull($order->paid_at);
        $this->assertNotNull($order->fulfilled_at);
        $this->assertNull($order->expires_at);
        $this->assertSame('20.00', $order->grand_total);

        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertSame(self::TRANSACTION, $payment->provider_payment_id);
        $this->assertSame('20.00', $payment->amount);
        $this->assertNotNull($payment->paid_at);

        $this->assertSame(1, $order->events()->where('type', OrderEvent::PAID)->count());
        $this->assertSame(1, $order->events()->where('type', OrderEvent::FULFILLED)->count());
    }

    #[Test]
    public function the_payment_keeps_what_the_shop_recorded_and_adds_what_tebex_said(): void
    {
        [$order, $payment] = $this->placeOrder();
        $nonce = $payment->raw['nonce'];

        $this->deliverToTebexEndpoint($this->tebexDelivery('payment.completed', $this->tebexPaymentFor($order, $payment)))->assertOk();

        $raw = $payment->fresh()->raw;
        $this->assertSame($nonce, $raw['nonce']);
        $this->assertSame('10.00', $raw['quoted']['base_price']);
        $this->assertSame(self::TRANSACTION, $raw['transaction_id']);
        $this->assertEquals(['amount' => '10.00', 'currency' => 'USD'], $raw['paid']);
        $this->assertEquals(['username' => 'ProGamer', 'username_id' => '1234'], $raw['buyer'], 'the account Tebex delivered to');
    }

    #[Test]
    public function the_order_says_what_was_charged_when_tebex_added_tax(): void
    {
        [$order] = $this->placeAndPay(price: '10.00', subject: ['price_paid' => ['amount' => 12.0, 'currency' => 'USD'], 'price' => ['amount' => 12.0, 'currency' => 'USD']]);

        $this->assertSame('10.00', $order->subtotal);
        $this->assertSame('2.00', $order->tax_total);
        $this->assertSame('0.00', $order->discount_total);
        $this->assertSame('12.00', $order->grand_total);
        $this->assertSame('12.00', $order->payments()->sole()->amount);
        $this->assertStringContainsString('Tebex took 12.00 USD, not the 10.00 the shop quoted', (string) $order->events()->where('type', OrderEvent::NOTE)->sole()->message);
    }

    #[Test]
    public function the_order_says_what_was_charged_when_a_tebex_code_took_something_off(): void
    {
        [$order] = $this->placeAndPay(price: '10.00', subject: ['price_paid' => ['amount' => 7.5, 'currency' => 'USD']]);

        $this->assertSame('0.00', $order->tax_total);
        $this->assertSame('2.50', $order->discount_total);
        $this->assertSame('7.50', $order->grand_total);
    }

    #[Test]
    public function a_payment_for_exactly_the_quote_changes_no_total_and_adds_no_note(): void
    {
        [$order] = $this->placeAndPay(price: '10.00');

        $this->assertSame('10.00', $order->grand_total);
        $this->assertSame(0, $order->events()->where('type', OrderEvent::NOTE)->count());
    }

    #[Test]
    public function a_payment_of_nothing_is_still_a_payment(): void
    {
        [$order, $payment] = $this->placeAndPay(price: '10.00', subject: ['price_paid' => ['amount' => 0, 'currency' => 'USD']]);

        $this->assertSame(OrderPaymentStatus::Paid, $order->payment_status);
        $this->assertSame('0.00', $order->grand_total);
        $this->assertSame('10.00', $order->discount_total);
        $this->assertSame('0.00', $payment->amount);
    }

    #[Test]
    public function price_is_used_when_price_paid_is_not_given(): void
    {
        [$order, $payment] = $this->placeOrder();
        $subject = $this->tebexPaymentFor($order, $payment, ['price' => ['amount' => 11.0, 'currency' => 'USD']]);
        unset($subject['price_paid']);

        $this->deliverToTebexEndpoint($this->tebexDelivery('payment.completed', $subject))->assertOk();

        $this->assertSame('11.00', $order->fresh()->grand_total);
    }

    #[Test]
    public function the_cart_is_emptied_of_what_was_bought_and_keeps_what_was_added_since(): void
    {
        [$order, $payment] = $this->placeOrder(quantity: 2);
        $other = TebexPackage::factory()->priced('3.00')->create(['ident' => 'pet-cat']);
        $this->post(route('shop.cart.packages.store'), ['package_id' => $other->tebex_id, 'quantity' => 1]);

        $this->deliverToTebexEndpoint($this->tebexDelivery('payment.completed', $this->tebexPaymentFor($order, $payment)))->assertOk();

        $cart = Cart::query()->sole();
        $this->assertSame(CartManager::OPEN, $cart->status, 'something is still in it');
        $this->assertSame([$other->id], CartItem::query()->pluck('tebex_package_id')->all());
    }

    #[Test]
    public function a_cart_with_nothing_left_is_converted(): void
    {
        $this->placeAndPay();

        $this->assertSame(CartManager::CONVERTED, Cart::query()->sole()->status);
        $this->assertSame(0, CartItem::query()->count());
    }

    #[Test]
    public function the_basket_custom_data_is_found_at_the_top_of_the_payment_too(): void
    {
        [$order, $payment] = $this->placeOrder();
        $subject = $this->tebexPaymentFor($order, $payment, ['custom' => ['order' => $order->public_id, 'nonce' => $payment->raw['nonce']]]);
        $subject['products'][0]['custom'] = null;

        $this->deliverToTebexEndpoint($this->tebexDelivery('payment.completed', $subject))->assertOk();

        $this->assertSame(OrderPaymentStatus::Paid, $order->fresh()->payment_status);
    }

    #[Test]
    public function a_delivery_that_is_sent_again_changes_nothing(): void
    {
        [$order, $payment] = $this->placeOrder();
        $delivery = $this->tebexDelivery('payment.completed', $this->tebexPaymentFor($order, $payment), 'evt-1');

        $this->deliverToTebexEndpoint($delivery)->assertOk();
        $this->deliverToTebexEndpoint($delivery)->assertOk()->assertSee('already handled');

        $this->assertSame(1, BillingWebhookCall::query()->count());
        $this->assertSame(1, $order->events()->where('type', OrderEvent::PAID)->count());
    }

    #[Test]
    public function the_same_payment_under_a_new_delivery_id_changes_nothing_either(): void
    {
        [$order, $payment] = $this->placeOrder();
        $subject = $this->tebexPaymentFor($order, $payment);

        $this->deliver('payment.completed', $subject)->assertOk();
        $this->deliver('payment.completed', $subject)->assertOk();

        $this->assertSame(2, BillingWebhookCall::query()->count());
        $this->assertSame(1, $order->events()->where('type', OrderEvent::PAID)->count());
        $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()->status);
    }

    #[Test]
    public function another_payment_for_a_basket_already_settled_is_flagged_and_not_applied(): void
    {
        [$order, $payment] = $this->placeAndPay();
        Log::spy();

        $this->deliver('payment.completed', $this->tebexPaymentFor($order, $payment, ['transaction_id' => 'tbx-another', 'price_paid' => ['amount' => 99, 'currency' => 'USD']]))->assertOk();

        $this->assertSame(self::TRANSACTION, $payment->fresh()->provider_payment_id, 'the payment still describes the first');
        $this->assertSame('10.00', $order->fresh()->grand_total);
        $this->assertSame(1, $order->events()->where('type', OrderEvent::PAID)->count());
        $this->assertStringContainsString('tbx-another', (string) BillingWebhookCall::query()->latest('id')->first()->error);
        Log::shouldHaveReceived('warning')->once();
    }

    #[Test]
    public function a_payment_naming_the_wrong_nonce_is_not_applied_and_is_flagged(): void
    {
        [$order, $payment] = $this->placeOrder();
        $subject = $this->tebexPaymentFor($order, $payment);
        $subject['products'][0]['custom']['nonce'] = Str::random(32);
        Log::spy();

        $this->deliver('payment.completed', $subject)->assertOk();

        $this->assertSame(OrderPaymentStatus::Unpaid, $order->fresh()->payment_status);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertStringContainsString($order->public_id, (string) BillingWebhookCall::query()->sole()->error);
        Log::shouldHaveReceived('critical')->once();
    }

    #[Test]
    public function a_payment_naming_an_order_the_shop_does_not_have_is_not_applied_and_is_flagged(): void
    {
        [$order, $payment] = $this->placeOrder();
        $subject = $this->tebexPaymentFor($order, $payment);
        $subject['products'][0]['custom']['order'] = (string) Str::ulid();
        Log::spy();

        $this->deliver('payment.completed', $subject)->assertOk();

        $this->assertSame(OrderPaymentStatus::Unpaid, $order->fresh()->payment_status);
        $this->assertNotNull(BillingWebhookCall::query()->sole()->error);
        Log::shouldHaveReceived('critical')->once();
    }

    #[Test]
    public function a_payment_naming_no_order_is_left_alone_and_the_call_says_why(): void
    {
        [$order, $payment] = $this->placeOrder();
        $subject = $this->tebexPaymentFor($order, $payment);
        $subject['products'][0]['custom'] = null;

        $this->deliver('payment.completed', $subject)->assertOk();

        $this->assertSame(OrderPaymentStatus::Unpaid, $order->fresh()->payment_status);
        $this->assertStringContainsString('names no order', (string) BillingWebhookCall::query()->sole()->error);
    }

    #[Test]
    public function an_order_id_alone_is_not_enough_to_pay_for_an_order(): void
    {
        [$order, $payment] = $this->placeOrder();
        $subject = $this->tebexPaymentFor($order, $payment);
        $subject['products'][0]['custom'] = ['order' => $order->public_id];

        $this->deliver('payment.completed', $subject)->assertOk();

        $this->assertSame(OrderPaymentStatus::Unpaid, $order->fresh()->payment_status);
    }

    #[Test]
    public function a_payment_in_another_currency_is_not_applied_and_waits_for_a_person(): void
    {
        [$order, $payment] = $this->placeOrder();
        Log::spy();

        $this->deliver('payment.completed', $this->tebexPaymentFor($order, $payment, ['price_paid' => ['amount' => 10.0, 'currency' => 'GBP']]))->assertOk();

        $this->assertSame(OrderPaymentStatus::Unpaid, $order->fresh()->payment_status);
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertSame(PaymentStatus::Review, $payment->fresh()->status);
        $this->assertSame(self::TRANSACTION, $payment->fresh()->provider_payment_id);
        $this->assertNotNull(BillingWebhookCall::query()->sole()->error);
        Log::shouldHaveReceived('critical')->once();
    }

    #[Test]
    public function a_payment_with_no_amount_is_not_applied_either(): void
    {
        [$order, $payment] = $this->placeOrder();
        $subject = $this->tebexPaymentFor($order, $payment);
        unset($subject['price_paid'], $subject['price']);

        $this->deliver('payment.completed', $subject)->assertOk();

        $this->assertSame(PaymentStatus::Review, $payment->fresh()->status);
        $this->assertSame(OrderPaymentStatus::Unpaid, $order->fresh()->payment_status);
    }

    #[Test]
    public function a_payment_with_no_transaction_id_is_not_applied(): void
    {
        [$order, $payment] = $this->placeOrder();
        $subject = $this->tebexPaymentFor($order, $payment);
        unset($subject['transaction_id']);

        $this->deliver('payment.completed', $subject)->assertOk();

        $this->assertSame(OrderPaymentStatus::Unpaid, $order->fresh()->payment_status);
        $this->assertNotNull(BillingWebhookCall::query()->sole()->error);
    }

    #[Test]
    public function a_payment_that_arrives_after_the_order_was_cancelled_is_honoured(): void
    {
        [$order, $payment] = $this->placeOrder();
        app(OrderLifecycle::class)->cancel($order, 'expired');
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);

        $this->deliver('payment.completed', $this->tebexPaymentFor($order, $payment))->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertSame(OrderPaymentStatus::Paid, $order->payment_status);
        $this->assertTrue($order->metadata['late_payment']);
    }

    #[Test]
    public function the_buyers_personal_details_are_not_kept(): void
    {
        [$order, $payment] = $this->placeOrder();
        $subject = $this->tebexPaymentFor($order, $payment, ['gift_cards' => [['card_number' => str_repeat('7', 15), 'amount' => ['amount' => 5, 'currency' => 'USD']]]]);

        $this->deliver('payment.completed', $subject)->assertOk();

        $stored = (string) json_encode(BillingWebhookCall::query()->sole()->payload);
        foreach (['ada.private@example.test', '203.0.113.7', 'TE57 1NG', 'Ada', str_repeat('7', 15), 'marketing_consent'] as $private) {
            $this->assertStringNotContainsString($private, $stored);
        }
        foreach ([self::TRANSACTION, 'ProGamer', '"country":"GB"', $payment->raw['nonce']] as $kept) {
            $this->assertStringContainsString($kept, $stored);
        }
    }

    #[Test]
    public function a_failure_while_applying_is_kept_and_answered_so_tebex_sends_it_again(): void
    {
        [$order, $payment] = $this->placeOrder();
        $delivery = $this->tebexDelivery('payment.completed', $this->tebexPaymentFor($order, $payment, ['price_paid' => ['amount' => 12.0, 'currency' => 'USD']]), 'evt-1');

        $this->mock(OrderLifecycle::class, fn ($mock) => $mock->shouldReceive('markPaid')->once()->andThrow(new RuntimeException('database went away')));
        $this->deliverToTebexEndpoint($delivery)->assertStatus(500);

        $call = BillingWebhookCall::query()->sole();
        $this->assertNull($call->processed_at);
        $this->assertSame('database went away', $call->error);
        $this->assertSame('10.00', $order->fresh()->grand_total, 'nothing was half applied');

        $this->app->forgetInstance(OrderLifecycle::class);
        $this->deliverToTebexEndpoint($delivery)->assertOk();

        $call->refresh();
        $this->assertNotNull($call->processed_at);
        $this->assertNull($call->error);
        $this->assertSame(2, $call->attempts);
        $this->assertSame(OrderPaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertSame('12.00', $order->fresh()->grand_total);
    }

    #[Test]
    public function the_buyer_who_comes_back_sees_the_order_paid(): void
    {
        [$order] = $this->placeAndPay();

        $this->get(URL::signedRoute('shop.checkout.complete', ['order' => $order->public_id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('order.payment_status', 'paid'));
    }

    // --- A declined payment ------------------------------------------------------------------------------

    #[Test]
    public function a_declined_attempt_is_noted_and_the_order_stays_open_for_another(): void
    {
        [$order, $payment] = $this->placeOrder();
        $subject = $this->tebexPaymentFor($order, $payment, [
            'status' => ['id' => 18, 'description' => 'Declined'],
            'decline_reason' => ['code' => 'insufficient_funds', 'message' => 'The payment method was declined due to the customer having insufficient funds.'],
        ]);

        $this->deliver('payment.declined', $subject)->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertStringContainsString('insufficient funds', (string) $order->events()->where('type', OrderEvent::NOTE)->sole()->message);
    }

    #[Test]
    public function a_declined_attempt_for_a_paid_order_or_an_unknown_basket_is_ignored(): void
    {
        [$order, $payment] = $this->placeAndPay();
        $this->deliver('payment.declined', $this->tebexPaymentFor($order, $payment, ['transaction_id' => 'tbx-later']))->assertOk();
        $this->deliver('payment.declined', $this->tebexFixture('payment'))->assertOk();

        $this->assertSame(0, $order->events()->where('type', OrderEvent::NOTE)->count());
        $this->assertNull(BillingWebhookCall::query()->where('type', 'payment.declined')->whereNotNull('error')->first());
    }

    // --- A refund ----------------------------------------------------------------------------------------

    #[Test]
    public function a_refund_made_in_the_tebex_panel_is_recorded_and_the_order_says_so(): void
    {
        [$order, $payment] = $this->placeAndPay();

        $this->deliver('payment.refunded', $this->tebexPaymentFor($order, $payment, ['status' => ['id' => 2, 'description' => 'Refund']]))->assertOk();

        $refund = Refund::query()->sole();
        $this->assertSame('tebex', $refund->provider);
        $this->assertSame(self::TRANSACTION.'-refund', $refund->provider_reference);
        $this->assertSame(RefundStatus::Succeeded, $refund->status);
        $this->assertSame('10.00', $refund->amount);
        $this->assertSame($payment->id, $refund->payment_id);
        $this->assertFalse($refund->notify_customer);

        $order->refresh();
        $this->assertSame(OrderPaymentStatus::Refunded, $order->payment_status);
        $this->assertSame('10.00', $order->refunded_total);
        $this->assertSame(1, $order->events()->where('type', OrderEvent::REFUND_SUCCEEDED)->count());
    }

    #[Test]
    public function a_refund_of_a_payment_with_a_tax_and_a_code_refunds_what_was_paid(): void
    {
        [$order, $payment] = $this->placeAndPay(subject: ['price_paid' => ['amount' => 12.0, 'currency' => 'USD']]);

        $this->deliver('payment.refunded', $this->tebexPaymentFor($order, $payment, ['status' => ['id' => 2, 'description' => 'Refund'], 'price_paid' => ['amount' => 12.0, 'currency' => 'USD']]))->assertOk();

        $this->assertSame('12.00', Refund::query()->sole()->amount);
        $this->assertSame(OrderPaymentStatus::Refunded, $order->fresh()->payment_status, 'in full, not part');
    }

    #[Test]
    public function a_refund_that_is_still_pending_is_pending_until_it_goes_through(): void
    {
        [$order, $payment] = $this->placeAndPay();

        $this->deliver('payment.refunded', $this->tebexPaymentFor($order, $payment, ['status' => ['id' => 21, 'description' => 'Refund Pending']]))->assertOk();

        $this->assertSame(RefundStatus::Pending, Refund::query()->sole()->status);
        $this->assertSame(OrderPaymentStatus::Paid, $order->fresh()->payment_status);

        $this->deliver('payment.refunded', $this->tebexPaymentFor($order, $payment, ['status' => ['id' => 2, 'description' => 'Refund']]))->assertOk();

        $this->assertSame(1, Refund::query()->count());
        $this->assertSame(RefundStatus::Succeeded, Refund::query()->sole()->status);
        $this->assertSame(OrderPaymentStatus::Refunded, $order->fresh()->payment_status);
    }

    #[Test]
    public function the_same_refund_delivered_twice_is_one_refund(): void
    {
        [$order, $payment] = $this->placeAndPay();
        $subject = $this->tebexPaymentFor($order, $payment, ['status' => ['id' => 2, 'description' => 'Refund']]);

        $this->deliver('payment.refunded', $subject)->assertOk();
        $this->deliver('payment.refunded', $subject)->assertOk();

        $this->assertSame(1, Refund::query()->count());
        $this->assertSame('10.00', $order->fresh()->refunded_total);
        $this->assertSame(1, $order->events()->where('type', OrderEvent::REFUND_SUCCEEDED)->count());
    }

    #[Test]
    public function a_refund_the_shop_already_recorded_by_hand_is_not_counted_twice(): void
    {
        [$order, $payment] = $this->placeAndPay();
        Refund::query()->create([
            'order_id' => $order->id, 'payment_id' => $payment->id, 'idempotency_key' => 'by-hand', 'status' => RefundStatus::Succeeded,
            'amount' => '10.00', 'currency' => 'USD', 'processed_at' => now(),
        ]);

        $this->deliver('payment.refunded', $this->tebexPaymentFor($order, $payment, ['status' => ['id' => 2, 'description' => 'Refund']]))->assertOk();

        $this->assertSame(1, Refund::query()->count());
        $this->assertStringContainsString('already shows it refunded in full', (string) $order->events()->where('type', OrderEvent::NOTE)->latest('id')->first()->message);
    }

    #[Test]
    public function a_refund_beyond_what_the_order_has_left_is_capped_at_what_is_left(): void
    {
        [$order, $payment] = $this->placeAndPay();
        Refund::query()->create([
            'order_id' => $order->id, 'payment_id' => $payment->id, 'idempotency_key' => 'by-hand', 'status' => RefundStatus::Succeeded,
            'amount' => '3.00', 'currency' => 'USD', 'processed_at' => now(),
        ]);

        $this->deliver('payment.refunded', $this->tebexPaymentFor($order, $payment, ['status' => ['id' => 2, 'description' => 'Refund']]))->assertOk();

        $this->assertSame('7.00', Refund::query()->where('provider', 'tebex')->sole()->amount);
        $this->assertSame('10.00', $order->fresh()->refunded_total);
    }

    #[Test]
    public function a_refund_for_a_payment_the_shop_does_not_know_is_flagged(): void
    {
        $this->placeAndPay();
        $subject = $this->tebexFixture('payment');
        $subject['transaction_id'] = 'tbx-unknown';
        $subject['status'] = ['id' => 2, 'description' => 'Refund'];

        $this->deliver('payment.refunded', $subject)->assertOk();

        $this->assertSame(0, Refund::query()->count());
        $this->assertStringContainsString('no record', (string) BillingWebhookCall::query()->latest('id')->first()->error);
    }

    #[Test]
    public function a_refunded_delivery_that_is_not_a_refund_is_ignored(): void
    {
        [$order, $payment] = $this->placeAndPay();

        $this->deliver('payment.refunded', $this->tebexPaymentFor($order, $payment))->assertOk();

        $this->assertSame(0, Refund::query()->count());
    }

    // --- A dispute ---------------------------------------------------------------------------------------

    #[Test]
    public function an_opened_dispute_is_noted_for_staff_and_changes_nothing_else(): void
    {
        [$order, $payment] = $this->placeAndPay();
        Log::spy();

        $this->deliver('payment.dispute.opened', $this->tebexPaymentFor($order, $payment, ['status' => ['id' => 3, 'description' => 'Chargeback']]))->assertOk();

        $this->assertSame(OrderPaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertSame(0, Refund::query()->count());
        $this->assertStringContainsString('dispute (chargeback) has been opened', (string) $order->events()->where('type', OrderEvent::NOTE)->sole()->message);
        Log::shouldHaveReceived('warning')->once();
    }

    #[Test]
    public function a_dispute_that_is_won_or_closed_is_only_noted(): void
    {
        [$order, $payment] = $this->placeAndPay();

        $this->deliver('payment.dispute.won', $this->tebexPaymentFor($order, $payment))->assertOk();
        $this->deliver('payment.dispute.closed', $this->tebexPaymentFor($order, $payment))->assertOk();

        $this->assertSame(0, Refund::query()->count());
        $this->assertSame(2, $order->events()->where('type', OrderEvent::NOTE)->count());
    }

    #[Test]
    public function a_dispute_that_is_lost_is_money_gone_and_the_order_says_refunded(): void
    {
        [$order, $payment] = $this->placeAndPay();

        $this->deliver('payment.dispute.lost', $this->tebexPaymentFor($order, $payment, ['status' => ['id' => 3, 'description' => 'Chargeback']]))->assertOk();

        $refund = Refund::query()->sole();
        $this->assertSame(self::TRANSACTION.'-dispute', $refund->provider_reference);
        $this->assertSame(RefundStatus::Succeeded, $refund->status);
        $this->assertSame(OrderPaymentStatus::Refunded, $order->fresh()->payment_status);
    }

    #[Test]
    public function a_lost_dispute_after_a_refund_does_not_refund_twice(): void
    {
        [$order, $payment] = $this->placeAndPay();

        $this->deliver('payment.refunded', $this->tebexPaymentFor($order, $payment, ['status' => ['id' => 2, 'description' => 'Refund']]))->assertOk();
        $this->deliver('payment.dispute.lost', $this->tebexPaymentFor($order, $payment))->assertOk();

        $this->assertSame(1, Refund::query()->count());
    }

    #[Test]
    public function a_dispute_whose_payment_is_wrapped_is_found_too(): void
    {
        [$order, $payment] = $this->placeAndPay();

        $this->deliver('payment.dispute.opened', ['id' => 'dsp-1', 'payment' => $this->tebexPaymentFor($order, $payment)])->assertOk();

        $this->assertSame(1, $order->events()->where('type', OrderEvent::NOTE)->count());
    }

    #[Test]
    public function a_dispute_on_a_payment_the_shop_does_not_know_is_flagged(): void
    {
        $this->deliver('payment.dispute.opened', ['transaction_id' => 'tbx-unknown'])->assertOk();
        $this->deliver('payment.dispute.opened', [])->assertOk();

        $this->assertSame(2, BillingWebhookCall::query()->whereNotNull('error')->count());
    }

    // --- Recurring payments are a later slice ------------------------------------------------------------

    #[Test]
    public function recurring_payment_deliveries_are_kept_and_acknowledged_but_not_acted_on(): void
    {
        [$order] = $this->placeAndPay();

        foreach (['recurring-payment.started', 'recurring-payment.renewed', 'recurring-payment.ended', 'recurring-payment.cancellation.requested', 'recurring-payment.cancellation.aborted', 'recurring-payment.status-changed'] as $type) {
            $this->deliver($type, ['reference' => 'tbx-r-1', 'status' => ['id' => 2, 'description' => 'Active']])->assertOk();
        }

        $this->assertSame(6, BillingWebhookCall::query()->where('provider', 'tebex')->where('type', 'like', 'recurring-payment.%')->count());
        $this->assertSame(OrderPaymentStatus::Paid, $order->fresh()->payment_status);
    }

    // --- Staff -------------------------------------------------------------------------------------------

    private function staff(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $staff = User::factory()->create();
        // Looking at a delivery needs view; running it again needs edit.
        $staff->givePermissionTo(['billing.acp.view', 'billing.acp.edit']);

        return $staff;
    }

    #[Test]
    public function staff_see_the_tebex_deliveries_and_which_ones_need_a_look(): void
    {
        [$order, $payment] = $this->placeOrder();
        $this->deliver('payment.completed', $this->tebexPaymentFor($order, $payment), 'evt-ok')->assertOk();
        $this->deliver('payment.completed', ['transaction_id' => 'tbx-orphan'] + $this->tebexFixture('payment'), 'evt-orphan')->assertOk();
        BillingWebhookCall::factory()->create(['stripe_id' => 'evt_stripe']);
        $staff = $this->staff();

        $this->actingAs($staff)->get(route('acp.billing.webhooks.index', ['provider' => 'tebex']))
            ->assertInertia(fn ($page) => $page
                ->has('calls.data', 2)
                ->where('availableProviders', fn ($providers) => in_array('tebex', collect($providers)->all(), true))
                ->where('filters.provider', 'tebex'));

        $this->actingAs($staff)->get(route('acp.billing.webhooks.index', ['provider' => 'tebex', 'processed' => 'attention']))
            ->assertInertia(fn ($page) => $page
                ->has('calls.data', 1)
                ->where('calls.data.0.external_id', 'evt-orphan')
                ->where('calls.data.0.provider', 'tebex')
                ->where('calls.data.0.error', fn ($error) => str_contains((string) $error, 'names no order')));

        $this->actingAs($staff)->get(route('acp.billing.webhooks.index', ['search' => 'evt-ok']))
            ->assertInertia(fn ($page) => $page->has('calls.data', 1)->where('calls.data.0.external_id', 'evt-ok'));
    }

    #[Test]
    public function a_delivery_can_be_opened_with_its_note(): void
    {
        $this->deliver('payment.completed', ['transaction_id' => 'tbx-orphan'] + $this->tebexFixture('payment'), 'evt-orphan')->assertOk();
        $call = BillingWebhookCall::query()->sole();

        $this->actingAs($this->staff())->get(route('acp.billing.webhooks.show', $call))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('acp/BillingWebhookCallView')
                ->where('call.provider', 'tebex')
                ->where('call.external_id', 'evt-orphan')
                ->where('call.attempts', 1)
                ->where('call.error', fn ($error) => str_contains((string) $error, 'names no order')));
    }

    #[Test]
    public function replaying_a_delivery_that_failed_applies_it(): void
    {
        [$order, $payment] = $this->placeOrder();
        $delivery = $this->tebexDelivery('payment.completed', $this->tebexPaymentFor($order, $payment), 'evt-1');

        $this->mock(OrderLifecycle::class, fn ($mock) => $mock->shouldReceive('markPaid')->once()->andThrow(new RuntimeException('database went away')));
        $this->deliverToTebexEndpoint($delivery)->assertStatus(500);
        $this->app->forgetInstance(OrderLifecycle::class);
        $call = BillingWebhookCall::query()->sole();

        $this->actingAs($this->staff())->post(route('acp.billing.webhooks.replay', $call))
            ->assertRedirect(route('acp.billing.webhooks.show', $call))
            ->assertSessionHas('success', 'Webhook replayed.');

        $this->assertSame(OrderPaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertNotNull($call->fresh()->processed_at);
        $this->assertNull($call->fresh()->error);
    }

    #[Test]
    public function replaying_one_that_was_applied_changes_nothing_and_says_so(): void
    {
        [$order] = $this->placeAndPay();
        $call = BillingWebhookCall::query()->sole();

        $this->actingAs($this->staff())->post(route('acp.billing.webhooks.replay', $call))
            ->assertSessionHas('info', 'This delivery was already processed, so nothing was applied again.');

        $this->assertSame(1, $order->events()->where('type', OrderEvent::PAID)->count());
    }

    #[Test]
    public function replaying_one_that_fails_again_says_so(): void
    {
        [$order, $payment] = $this->placeOrder();
        $this->mock(OrderLifecycle::class, fn ($mock) => $mock->shouldReceive('markPaid')->twice()->andThrow(new RuntimeException('still down')));
        $this->deliverToTebexEndpoint($this->tebexDelivery('payment.completed', $this->tebexPaymentFor($order, $payment), 'evt-1'))->assertStatus(500);
        $call = BillingWebhookCall::query()->sole();

        $this->actingAs($this->staff())->post(route('acp.billing.webhooks.replay', $call))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'failed again'));

        $this->assertSame('still down', $call->fresh()->error);
        $this->assertSame(2, $call->fresh()->attempts);
    }

    #[Test]
    public function a_stored_delivery_that_cannot_be_read_cannot_be_replayed(): void
    {
        $call = BillingWebhookCall::query()->create(['provider' => 'tebex', 'external_id' => 'evt-bad', 'type' => 'payment.completed', 'payload' => ['nonsense' => true]]);

        $this->actingAs($this->staff())->post(route('acp.billing.webhooks.replay', $call))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'missing or malformed'));
    }

    #[Test]
    public function only_staff_who_may_edit_billing_can_replay(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $call = BillingWebhookCall::query()->create(['provider' => 'tebex', 'external_id' => 'evt-1', 'type' => 'basket.abandoned', 'payload' => ['id' => 'evt-1', 'type' => 'basket.abandoned', 'subject' => []]]);

        $this->actingAs(User::factory()->create())->post(route('acp.billing.webhooks.replay', $call))->assertForbidden();

        // Being let in to look is not enough: running a delivery again makes it do what it does.
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('billing.acp.view');
        $this->actingAs($viewer)->post(route('acp.billing.webhooks.replay', $call))->assertForbidden();
    }

    // --- A payment that must not be believed -------------------------------------------------------------

    #[Test]
    public function a_negative_amount_is_no_amount(): void
    {
        [$order, $payment] = $this->placeOrder();
        $negative = ['amount' => -5, 'currency' => 'USD'];

        $this->deliver('payment.completed', $this->tebexPaymentFor($order, $payment, ['price_paid' => $negative, 'price' => $negative]))->assertOk();

        $this->assertSame(PaymentStatus::Review, $payment->fresh()->status);
        $this->assertSame(OrderPaymentStatus::Unpaid, $order->fresh()->payment_status);
    }

    #[Test]
    public function a_repeat_of_a_settled_payment_cannot_unsettle_it(): void
    {
        [$order, $payment] = $this->placeAndPay();

        $this->deliver('payment.completed', $this->tebexPaymentFor($order, $payment, ['price_paid' => ['amount' => 10.0, 'currency' => 'GBP']]))->assertOk();

        $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()->status);
        $this->assertSame(OrderPaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertNull(BillingWebhookCall::query()->latest('id')->first()->error);
    }

    #[Test]
    public function a_second_basket_paid_for_an_order_already_settled_leaves_the_order_as_it_is(): void
    {
        [$order] = $this->placeAndPay();
        $second = Payment::query()->create([
            'order_id' => $order->id, 'provider' => 'tebex', 'provider_reference' => 'bsk-second', 'status' => PaymentStatus::Pending,
            'amount' => '10.00', 'currency' => 'USD', 'raw' => ['nonce' => Str::random(32)],
        ]);

        $this->deliver('payment.completed', $this->tebexPaymentFor($order, $second, ['transaction_id' => 'tbx-second', 'price_paid' => ['amount' => 14.0, 'currency' => 'USD']]))->assertOk();

        $order->refresh();
        $this->assertSame('10.00', $order->grand_total, 'the order is what the first payment settled');
        $this->assertSame('0.00', $order->tax_total);
        $this->assertSame(PaymentStatus::Succeeded, $second->fresh()->status, 'the money was taken, so a person has to refund it');
        $this->assertSame(1, $order->events()->where('type', OrderEvent::PAID)->count());
    }

    #[Test]
    public function a_completed_delivery_whose_payment_is_not_complete_is_not_applied(): void
    {
        [$order, $payment] = $this->placeOrder();

        foreach ([18 => 'Declined', 19 => 'Pending Checkout', 3 => 'Chargeback'] as $id => $description) {
            $this->deliver('payment.completed', $this->tebexPaymentFor($order, $payment, ['status' => ['id' => $id, 'description' => $description]]))->assertOk();
        }

        $this->assertSame(OrderPaymentStatus::Unpaid, $order->fresh()->payment_status);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(3, BillingWebhookCall::query()->whereNotNull('error')->count());
    }

    #[Test]
    public function a_payment_that_gives_no_status_is_taken_as_it_comes(): void
    {
        [$order, $payment] = $this->placeOrder();
        $subject = $this->tebexPaymentFor($order, $payment);
        unset($subject['status']);

        $this->deliver('payment.completed', $subject)->assertOk();

        $this->assertSame(OrderPaymentStatus::Paid, $order->fresh()->payment_status);
    }

    // --- What was bought must be what was ordered --------------------------------------------------------------------

    private function anotherPackage(string $ident = 'cheap-thing', string $price = '1.00'): TebexPackage
    {
        return TebexPackage::factory()->priced($price)->create(['ident' => $ident, 'name' => Str::headline($ident)]);
    }

    /**
     * Assert that a payment was taken for something other than the order, so the shop held it and fulfilled nothing.
     */
    private function assertHeld(Order $order, Payment $payment): void
    {
        $this->assertSame(PaymentStatus::Review, $payment->fresh()->status);
        $this->assertSame(self::TRANSACTION, $payment->fresh()->provider_payment_id);
        $this->assertSame(OrderPaymentStatus::Unpaid, $order->fresh()->payment_status);
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertSame(0, $order->events()->where('type', OrderEvent::PAID)->count());
        $this->assertSame(1, CartItem::query()->count(), 'the cart still has what the buyer asked for');
        $this->assertStringContainsString('not what order', (string) BillingWebhookCall::query()->latest('id')->first()->error);
    }

    #[Test]
    public function a_payment_for_a_cheaper_package_than_the_one_ordered_is_held_not_fulfilled(): void
    {
        [$order, $payment] = $this->placeOrder(price: '10.00');
        $cheap = $this->anotherPackage();
        $subject = $this->tebexPaymentFor($order, $payment, ['price_paid' => ['amount' => 1.0, 'currency' => 'USD'], 'price' => ['amount' => 1.0, 'currency' => 'USD']]);
        $subject['products'][0]['id'] = $cheap->tebex_id;
        Log::spy();

        $this->deliver('payment.completed', $subject)->assertOk();

        $this->assertHeld($order, $payment);
        $this->assertSame('10.00', $order->fresh()->grand_total, 'the order is not rewritten to what was bought instead');
        $this->assertEquals([$cheap->tebex_id => 1], $payment->fresh()->raw['purchased']);
        Log::shouldHaveReceived('critical')->once();
    }

    #[Test]
    public function a_payment_for_fewer_of_a_package_than_ordered_is_held(): void
    {
        [$order, $payment] = $this->placeOrder(quantity: 2);
        $subject = $this->tebexPaymentFor($order, $payment);
        $subject['products'][0]['quantity'] = 1;

        $this->deliver('payment.completed', $subject)->assertOk();

        $this->assertHeld($order, $payment);
    }

    #[Test]
    public function a_payment_for_more_than_was_ordered_is_held_too(): void
    {
        [$order, $payment] = $this->placeOrder();
        $subject = $this->tebexPaymentFor($order, $payment);
        $subject['products'][] = [...$subject['products'][0], 'id' => $this->anotherPackage()->tebex_id];

        $this->deliver('payment.completed', $subject)->assertOk();

        $this->assertHeld($order, $payment);
    }

    #[Test]
    #[DataProvider('paymentsListingNoPackages')]
    public function a_payment_that_lists_no_packages_is_held(?array $products): void
    {
        [$order, $payment] = $this->placeOrder();
        $subject = $this->tebexPaymentFor($order, $payment);
        unset($subject['products']);
        $products === null || $subject['products'] = $products;
        // The custom data that finds the order travels on the products, so it goes at the top of the payment here.
        $subject['custom'] = ['order' => $order->public_id, 'nonce' => $payment->raw['nonce']];

        $this->deliver('payment.completed', $subject)->assertOk();

        $this->assertHeld($order, $payment);
    }

    /**
     * @return array<string, array{0: array<int, mixed>|null}>
     */
    public static function paymentsListingNoPackages(): array
    {
        return ['no products at all' => [null], 'an empty list' => [[]]];
    }

    #[Test]
    #[DataProvider('unreadablePackageEntries')]
    public function a_package_entry_that_cannot_be_read_holds_the_payment(array $broken): void
    {
        [$order, $payment] = $this->placeOrder();
        $subject = $this->tebexPaymentFor($order, $payment);
        $subject['products'][0] = [...$subject['products'][0], ...$broken];

        $this->deliver('payment.completed', $subject)->assertOk();

        $this->assertHeld($order, $payment);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function unreadablePackageEntries(): array
    {
        return [
            'an id that is not a number' => [['id' => 'abc']],
            'no id' => [['id' => null]],
            'an id that is a fraction' => [['id' => 4.5]],
            'a quantity of none' => [['quantity' => 0]],
            'a negative quantity' => [['quantity' => '-1']],
            'a fractional quantity' => [['quantity' => 1.5]],
            'no quantity' => [['quantity' => null]],
        ];
    }

    #[Test]
    public function an_order_line_the_shop_can_no_longer_tie_to_a_package_cannot_be_checked_so_the_payment_is_held(): void
    {
        [$order, $payment] = $this->placeOrder();
        $order->items()->update(['tebex_package_id' => null]);

        $this->deliver('payment.completed', $this->tebexPaymentFor($order->fresh(), $payment, ['products' => [['id' => 4, 'quantity' => 1, 'custom' => ['order' => $order->public_id, 'nonce' => $payment->raw['nonce']]]]]))->assertOk();

        $this->assertSame(PaymentStatus::Review, $payment->fresh()->status);
        $this->assertSame(OrderPaymentStatus::Unpaid, $order->fresh()->payment_status);
    }

    #[Test]
    public function a_held_payment_stays_held_when_the_delivery_comes_again(): void
    {
        [$order, $payment] = $this->placeOrder();
        $subject = $this->tebexPaymentFor($order, $payment);
        $subject['products'][0]['id'] = $this->anotherPackage()->tebex_id;

        $this->deliver('payment.completed', $subject)->assertOk();
        $this->deliver('payment.completed', $subject)->assertOk();

        $this->assertHeld($order, $payment);
    }

    #[Test]
    public function the_packages_may_come_in_any_order_ids_may_be_text_and_a_package_listed_twice_adds_up(): void
    {
        $extra = $this->anotherPackage('pet-cat', '3.00');
        $this->post(route('shop.cart.packages.store'), ['package_id' => $extra->tebex_id, 'quantity' => 1]);
        [$order, $payment] = $this->placeOrder(quantity: 2);
        $this->assertCount(2, $order->items);

        $subject = $this->tebexPaymentFor($order, $payment);
        [$cat, $vip] = $subject['products'];
        // The cat once, the VIP rank listed as two lines of one (with its id and quantity as text), in the other order.
        $subject['products'] = [[...$vip, 'id' => (string) $vip['id'], 'quantity' => 1], $cat, [...$vip, 'quantity' => '1']];

        $this->deliver('payment.completed', $subject)->assertOk();

        $this->assertSame(OrderPaymentStatus::Paid, $order->fresh()->payment_status);
    }

    // --- The account the staff see -------------------------------------------------------------------------------------

    #[Test]
    public function staff_see_the_account_tebex_delivered_to_with_the_id_it_gave_as_text(): void
    {
        [$order] = $this->placeAndPay();
        $this->seed(RolePermissionSeeder::class);

        $this->actingAs(User::factory()->create()->assignRole('admin'))->get(route('acp.commerce.orders.show', $order))
            ->assertInertia(fn ($page) => $page->where('order.game_account', ['username' => 'ProGamer', 'id' => '1234']));
    }

    #[Test]
    public function an_id_that_is_not_a_number_is_shown_as_it_is(): void
    {
        [$order, $payment] = $this->placeOrder();
        $subject = $this->tebexPaymentFor($order, $payment);
        $subject['customer']['username'] = ['id' => '069a79f4-44e9-4726-a5be-fca90e38aaf5', 'username' => 'Notch'];
        $this->deliver('payment.completed', $subject)->assertOk();
        $this->seed(RolePermissionSeeder::class);

        $this->actingAs(User::factory()->create()->assignRole('admin'))->get(route('acp.commerce.orders.show', $order))
            ->assertInertia(fn ($page) => $page->where('order.game_account', ['username' => 'Notch', 'id' => '069a79f4-44e9-4726-a5be-fca90e38aaf5']));
    }

    #[Test]
    public function an_entry_that_cannot_be_read_holds_the_payment_even_beside_ones_that_match(): void
    {
        [$order, $payment] = $this->placeOrder();
        $subject = $this->tebexPaymentFor($order, $payment);
        $subject['products'][] = ['id' => 'abc', 'quantity' => 1];

        $this->deliver('payment.completed', $subject)->assertOk();

        $this->assertHeld($order, $payment);
    }

    #[Test]
    public function a_negative_quantity_cannot_cancel_out_another_entry(): void
    {
        [$order, $payment] = $this->placeOrder(quantity: 2);
        $subject = $this->tebexPaymentFor($order, $payment);
        $subject['products'][0]['quantity'] = 3;
        $subject['products'][] = [...$subject['products'][0], 'quantity' => -1];

        $this->deliver('payment.completed', $subject)->assertOk();

        $this->assertHeld($order, $payment);
    }

    #[Test]
    public function an_order_with_a_line_the_shop_can_no_longer_tie_to_a_package_is_not_paid_on_the_strength_of_the_others(): void
    {
        $extra = $this->anotherPackage('pet-cat', '3.00');
        $this->post(route('shop.cart.packages.store'), ['package_id' => $extra->tebex_id, 'quantity' => 1]);
        [$order, $payment] = $this->placeOrder();
        $subject = $this->tebexPaymentFor($order, $payment);
        $order->items()->where('tebex_package_id', $extra->id)->update(['tebex_package_id' => null]);

        // Tebex took money for the VIP rank alone: the order's other line cannot be said to have been paid for.
        $subject['products'] = [$subject['products'][1]];
        $this->deliver('payment.completed', $subject)->assertOk();

        $this->assertSame(PaymentStatus::Review, $payment->fresh()->status);
        $this->assertSame(OrderPaymentStatus::Unpaid, $order->fresh()->payment_status);
    }
}
