<?php

namespace Tests\Feature\Tebex;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\BillingWebhookCall;
use App\Models\DownloadGrant;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductFile;
use App\Models\Refund;
use App\Models\TebexSync;
use App\Models\User;
use App\Payments\Capability;
use App\Payments\Providers\StripeProvider;
use App\Payments\Providers\TebexProvider;
use App\Payments\Tebex\TebexCatalogue;
use App\Payments\Tebex\TebexCheckoutClient;
use App\Payments\Tebex\TebexException;
use App\Support\Commerce\Money;
use App\Support\Commerce\OrderRefunder;
use App\Support\Commerce\RefundException;
use App\Support\Commerce\RefundRequest;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeTebexCheckoutApi;
use Tests\Support\InteractsWithCommerce;
use Tests\Support\InteractsWithTebex;
use Tests\Support\InteractsWithTebexWebhooks;
use Tests\TestCase;

/**
 * The second half of selling the shop's own products through Tebex's Checkout API (experimental): refunding a payment through
 * the API, looking a payment up when its delivery was lost, and the ACP page for the mode. Tested against the shape of Tebex's
 * documentation (`tests/Support/FakeTebexCheckoutApi`), never a live account.
 */
class TebexCheckoutApiRefundsTest extends TestCase
{
    use InteractsWithCommerce;
    use InteractsWithTebex;
    use InteractsWithTebexWebhooks;
    use RefreshDatabase;

    /** The transaction id the documentation's example payment has. */
    private const TRANSACTION = 'tbx-4a1b2c3d4e5f';

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
        $this->seed(RolePermissionSeeder::class);

        $this->api = $this->fakeTebexCheckoutApi();
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('admin');
    }

    /**
     * Check out a download as the buyer, without paying.
     *
     * @return array{0: Order, 1: Payment}
     */
    private function placedOrder(string $price = '15.00'): array
    {
        $product = Product::factory()->priced($price)->stocked(10)->create(['name' => 'Ebook', 'requires_shipping' => false]);
        ProductFile::factory()->for($product)->withContents('the file bytes')->create();

        $this->addToCart($product)->assertSessionHas('success');
        $this->post(route('shop.checkout.store'), ['email' => 'buyer@example.com', 'name' => 'Ada Buyer', 'token' => (string) Str::uuid()])->assertRedirect();

        $order = Order::query()->latest('id')->firstOrFail();

        return [$order, $order->payments()->firstOrFail()];
    }

    private function webhook(string $type, array $subject)
    {
        return $this->deliverToTebexEndpoint($this->tebexDelivery($type, $subject));
    }

    /**
     * An order the buyer has paid: Tebex has said so, and has the payment.
     *
     * @return array{0: Order, 1: Payment}
     */
    private function paidOrder(string $price = '15.00'): array
    {
        [$order, $payment] = $this->placedOrder($price);
        $subject = $this->tebexProductPaymentFor($order, $payment);

        $this->webhook('payment.completed', $subject)->assertOk();
        $this->api->pay($payment->provider_reference, $subject);

        return [$order->fresh(), $payment->fresh()];
    }

    private function refundAll(Order $order, bool $manual = false, ?Money $amount = null): Refund
    {
        return app(OrderRefunder::class)->request(
            $order,
            $amount ?? Money::parse($order->grand_total, $order->currency),
            new RefundRequest(token: (string) Str::uuid(), reason: 'requested_by_customer', notifyCustomer: false, manual: $manual),
        );
    }

    // --- What the provider can do --------------------------------------------------------------------------------------------

    #[Test]
    public function with_the_checkout_api_on_tebex_can_refund_but_only_a_whole_payment(): void
    {
        $capabilities = app(TebexProvider::class)->capabilities();

        $this->assertContains(Capability::REFUNDS, $capabilities);
        $this->assertNotContains(Capability::PARTIAL_REFUNDS, $capabilities, 'its API takes no amount');

        config(['tebex.checkout_api.enabled' => false]);

        $this->assertNotContains(Capability::REFUNDS, app(TebexProvider::class)->capabilities(), 'the Headless API cannot refund: it is done in the Tebex panel');
    }

    #[Test]
    public function stripe_still_refunds_part_of_a_payment(): void
    {
        $this->assertContains(Capability::PARTIAL_REFUNDS, app(StripeProvider::class)->capabilities());
    }

    #[Test]
    public function a_provider_that_can_only_refund_in_full_is_never_asked_for_part_of_a_payment(): void
    {
        [$order] = $this->paidOrder('15.00');

        try {
            $this->refundAll($order, amount: Money::parse('5.00', 'USD'));
            $this->fail('A part of a payment was asked of a provider that can only return all of it.');
        } catch (RefundException $exception) {
            $this->assertStringContainsString('can only refund a payment in full (15.00 USD)', $exception->getMessage());
            $this->assertSame('amount', $exception->field);
        }

        $this->assertSame(0, Refund::query()->count(), 'it is refused before there is a refund to fail');
        $this->assertSame([], $this->api->calls('refund'));
    }

    #[Test]
    public function less_than_the_whole_can_still_be_recorded_as_a_refund_made_another_way(): void
    {
        [$order] = $this->paidOrder('15.00');

        $refund = $this->refundAll($order, manual: true, amount: Money::parse('5.00', 'USD'));

        $this->assertSame(RefundStatus::Succeeded, $refund->status);
        $this->assertNull($refund->provider);
        $this->assertSame(OrderPaymentStatus::PartiallyRefunded, $order->fresh()->payment_status);
        $this->assertSame([], $this->api->calls('refund'), 'nothing is sent to Tebex for a refund made by hand');

        // And what is left is not the whole payment any more, so Tebex cannot be asked for it.
        $this->expectException(RefundException::class);
        $this->refundAll($order->fresh(), amount: Money::parse('10.00', 'USD'));
    }

    // --- Refunding through the API ----------------------------------------------------------------------------------------------

    #[Test]
    public function asked_directly_for_part_of_a_payment_the_provider_refuses_too(): void
    {
        [$order, $payment] = $this->paidOrder('15.00');
        $refund = Refund::query()->create([
            'order_id' => $order->id,
            'payment_id' => $payment->id,
            'provider' => 'tebex',
            'idempotency_key' => 'direct-1',
            'status' => RefundStatus::Pending,
            'amount' => '5.00',
            'currency' => 'USD',
        ]);

        $outcome = app(TebexProvider::class)->refund($refund);

        $this->assertSame(RefundStatus::Failed, $outcome->status);
        $this->assertSame('Tebex refunds a payment in full.', $outcome->failureReason);
        $this->assertSame([], $this->api->calls('refund'), 'nothing is sent that Tebex would take as the whole');
    }

    #[Test]
    public function a_payment_with_no_tebex_transaction_cannot_be_refunded(): void
    {
        [$order, $payment] = $this->placedOrder('15.00');
        $refund = Refund::query()->create([
            'order_id' => $order->id,
            'payment_id' => $payment->id,
            'provider' => 'tebex',
            'idempotency_key' => 'direct-2',
            'status' => RefundStatus::Pending,
            'amount' => '15.00',
            'currency' => 'USD',
        ]);

        $outcome = app(TebexProvider::class)->refund($refund);

        $this->assertSame(RefundStatus::Failed, $outcome->status);
        $this->assertStringContainsString('no Tebex transaction', (string) $outcome->failureReason);
        $this->assertSame([], $this->api->calls('refund'));
    }

    #[Test]
    public function a_whole_payment_is_refunded_through_the_api_and_takes_the_order_back(): void
    {
        [$order] = $this->paidOrder('15.00');
        $this->assertSame(1, DownloadGrant::query()->where('order_id', $order->id)->whereNull('revoked_at')->count());

        $refund = $this->refundAll($order);

        $call = $this->api->calls('refund')[0];
        $this->assertSame('POST', $call['method']);
        $this->assertSame('https://checkout.tebex.io/api/payments/'.self::TRANSACTION.'/refund?type=txn_id', $call['url']);
        $this->assertSame('Basic '.base64_encode('123456:private-key-that-must-never-be-shown'), $call['headers']['Authorization'][0]);

        $this->assertSame(RefundStatus::Succeeded, $refund->status);
        $this->assertSame('tebex', $refund->provider);
        $this->assertSame(self::TRANSACTION.'-refund', $refund->provider_reference);
        $this->assertSame(OrderPaymentStatus::Refunded, $order->fresh()->payment_status);
        $this->assertSame(0, DownloadGrant::query()->where('order_id', $order->id)->whereNull('revoked_at')->count(), 'the download follows the money');
    }

    #[Test]
    public function the_delivery_tebex_sends_after_a_refund_it_was_asked_for_is_the_same_refund(): void
    {
        [$order, $payment] = $this->paidOrder('15.00');
        $this->refundAll($order);

        $this->webhook('payment.refunded', $this->tebexProductPaymentFor($order, $payment, ['status' => ['id' => 2, 'description' => 'Refund']]))->assertOk();

        $this->assertSame(1, Refund::query()->count(), 'one refund, not the shop\'s and then Tebex\'s');
        $this->assertSame('15.00', $order->fresh()->refunded_total);
    }

    #[Test]
    public function a_refund_tebex_leaves_pending_is_pending_until_it_goes_through(): void
    {
        [$order, $payment] = $this->paidOrder('15.00');
        $this->api->refundStatus = 21;

        $refund = $this->refundAll($order);

        $this->assertSame(RefundStatus::Pending, $refund->status);
        $this->assertSame(self::TRANSACTION.'-refund', $refund->provider_reference);
        $this->assertSame(OrderPaymentStatus::Paid, $order->fresh()->payment_status, 'the money has not gone yet');

        $this->webhook('payment.refunded', $this->tebexProductPaymentFor($order, $payment, ['status' => ['id' => 2, 'description' => 'Refund']]))->assertOk();

        $this->assertSame(1, Refund::query()->count());
        $this->assertSame(RefundStatus::Succeeded, $refund->fresh()->status);
        $this->assertSame(OrderPaymentStatus::Refunded, $order->fresh()->payment_status);
    }

    #[Test]
    public function a_refusal_from_tebex_fails_the_refund_and_changes_nothing(): void
    {
        [$order] = $this->paidOrder('15.00');
        $this->api->failOn('refund', 400, detail: 'The payment cannot be refunded');

        $refund = $this->refundAll($order);

        $this->assertSame(RefundStatus::Failed, $refund->status);
        $this->assertStringContainsString('cannot be refunded', (string) $refund->failure_reason);
        $this->assertSame(OrderPaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertCount(1, $this->api->calls('refund'));
    }

    #[Test]
    public function tebex_declining_to_refund_without_an_error_fails_the_refund(): void
    {
        [$order] = $this->paidOrder('15.00');
        $this->api->refundStatus = 1;

        $refund = $this->refundAll($order);

        $this->assertSame(RefundStatus::Failed, $refund->status);
        $this->assertSame(OrderPaymentStatus::Paid, $order->fresh()->payment_status);
    }

    #[Test]
    public function an_answer_that_never_came_leaves_the_refund_pending_and_checking_finds_it(): void
    {
        [$order] = $this->paidOrder('15.00');
        $this->api->failOn('refund', 500, 5);

        $refund = $this->refundAll($order);

        $this->assertCount(1, $this->api->calls('refund'), 'it may have been made, and asking twice could refund twice');
        $this->assertSame(RefundStatus::Pending, $refund->status);
        $this->assertNull($refund->provider_reference);
        $this->assertTrue($order->events()->where('type', OrderEvent::REFUND_UNCONFIRMED)->exists());

        // It had gone through after all.
        $this->api->refundInPanel(self::TRANSACTION, 2);
        $checked = app(OrderRefunder::class)->check($refund);

        $this->assertSame(RefundStatus::Succeeded, $checked->status);
        $this->assertSame(1, Refund::query()->count(), 'the refund the shop asked for was found, not a second one made');
        $this->assertSame(OrderPaymentStatus::Refunded, $order->fresh()->payment_status);
    }

    #[Test]
    public function the_delivery_after_a_refund_nobody_heard_back_on_settles_that_refund(): void
    {
        [$order, $payment] = $this->paidOrder('15.00');
        $this->api->failOn('refund', 500, 5);
        $refund = $this->refundAll($order);
        $this->assertNull($refund->provider_reference, 'no answer, so no reference yet');

        // Tebex had made it. Its delivery arrives: this is that refund, further along, not a payment already refunded in full.
        $this->webhook('payment.refunded', $this->tebexProductPaymentFor($order, $payment, ['status' => ['id' => 2, 'description' => 'Refund']]))->assertOk();

        $this->assertSame(1, Refund::query()->count(), 'not a second refund beside the first');
        $this->assertSame(RefundStatus::Succeeded, $refund->fresh()->status);
        $this->assertSame(self::TRANSACTION.'-refund', $refund->fresh()->provider_reference);
        $this->assertSame(OrderPaymentStatus::Refunded, $order->fresh()->payment_status);
        $this->assertSame(0, DownloadGrant::query()->where('order_id', $order->id)->whereNull('revoked_at')->count(), 'the customer has their money and no longer the download');
    }

    #[Test]
    public function a_delivery_saying_the_refund_is_pending_gives_the_unanswered_refund_its_reference_and_the_next_one_settles_it(): void
    {
        [$order, $payment] = $this->paidOrder('15.00');
        $this->api->failOn('refund', 500, 5);
        $refund = $this->refundAll($order);

        $this->webhook('payment.refunded', $this->tebexProductPaymentFor($order, $payment, ['status' => ['id' => 21, 'description' => 'Refund Pending']]))->assertOk();

        $this->assertSame(RefundStatus::Pending, $refund->fresh()->status);
        $this->assertSame(self::TRANSACTION.'-refund', $refund->fresh()->provider_reference);

        $this->webhook('payment.refunded', $this->tebexProductPaymentFor($order, $payment, ['status' => ['id' => 2, 'description' => 'Refund']]))->assertOk();

        $this->assertSame(1, Refund::query()->count());
        $this->assertSame(RefundStatus::Succeeded, $refund->fresh()->status);
        $this->assertSame(OrderPaymentStatus::Refunded, $order->fresh()->payment_status);
    }

    #[Test]
    public function a_refund_tebex_refused_is_not_adopted_by_a_refund_made_later_in_the_panel(): void
    {
        [$order, $payment] = $this->paidOrder('15.00');
        $this->api->failOn('refund', 400, detail: 'Not refundable yet');
        $refused = $this->refundAll($order);
        $this->assertSame(RefundStatus::Failed, $refused->status);

        // Staff refund it in the Tebex panel instead; its delivery arrives.
        $this->webhook('payment.refunded', $this->tebexProductPaymentFor($order, $payment, ['status' => ['id' => 2, 'description' => 'Refund']]))->assertOk();

        $this->assertSame(RefundStatus::Failed, $refused->fresh()->status, 'a refund that was refused stays refused');
        $this->assertSame(2, Refund::query()->count(), 'the one made in the panel is recorded as its own');
        $this->assertSame(OrderPaymentStatus::Refunded, $order->fresh()->payment_status);
    }

    #[Test]
    public function a_lost_dispute_is_not_mistaken_for_the_refund_the_shop_asked_for(): void
    {
        [$order, $payment] = $this->paidOrder('15.00');
        $this->api->failOn('refund', 500, 5);
        $refund = $this->refundAll($order);

        $this->webhook('payment.dispute.lost', $this->tebexProductPaymentFor($order, $payment, ['status' => ['id' => 3, 'description' => 'Chargeback']]))->assertOk();

        $this->assertNull($refund->fresh()->provider_reference, 'what Tebex took back in a dispute is not what the shop asked it to return');
        $this->assertSame(RefundStatus::Pending, $refund->fresh()->status);
    }

    #[Test]
    public function a_refund_left_pending_is_still_checked_after_the_mode_is_switched_off(): void
    {
        [$order] = $this->paidOrder('15.00');
        $this->api->failOn('refund', 500, 5);
        $refund = $this->refundAll($order);

        config(['tebex.checkout_api.enabled' => false]);
        $this->api->refundInPanel(self::TRANSACTION, 2);

        $checked = app(OrderRefunder::class)->check($refund);

        $this->assertSame(RefundStatus::Succeeded, $checked->status, 'the payment was made through the API, whatever the mode is now');
        $this->assertSame(1, Refund::query()->count());
        $this->assertSame(OrderPaymentStatus::Refunded, $order->fresh()->payment_status);
    }

    #[Test]
    public function a_refund_with_a_reference_is_not_left_pending_for_good_when_the_mode_is_switched_off(): void
    {
        [$order] = $this->paidOrder('15.00');
        $this->api->refundStatus = 21;
        $refund = $this->refundAll($order);
        $this->assertSame(RefundStatus::Pending, $refund->status);

        config(['tebex.checkout_api.enabled' => false]);
        $this->api->refundInPanel(self::TRANSACTION, 2);

        $this->assertSame(RefundStatus::Succeeded, app(OrderRefunder::class)->check($refund)->status);
    }

    #[Test]
    public function a_refund_tebex_never_received_is_still_failed_after_the_time_it_could_have_been_in_flight(): void
    {
        [$order] = $this->paidOrder('15.00');
        $this->api->failOn('refund', 500, 5);
        $refund = $this->refundAll($order);
        $refund->forceFill(['created_at' => now()->subMinutes(11)])->save();

        // Tebex has no record of it: the payment is not refunded.
        $checked = app(OrderRefunder::class)->check($refund->fresh());

        $this->assertSame(RefundStatus::Failed, $checked->status);
        $this->assertSame(OrderPaymentStatus::Paid, $order->fresh()->payment_status);
    }

    #[Test]
    public function an_unanswered_refund_is_not_failed_when_tebex_made_it_and_the_mode_has_been_switched_off(): void
    {
        [$order] = $this->paidOrder('15.00');
        $this->api->failOn('refund', 500, 5);
        $refund = $this->refundAll($order);
        $refund->forceFill(['created_at' => now()->subMinutes(11)])->save();

        config(['tebex.checkout_api.enabled' => false]);
        $this->api->refundInPanel(self::TRANSACTION, 2);

        $checked = app(OrderRefunder::class)->check($refund->fresh());

        $this->assertSame(RefundStatus::Succeeded, $checked->status, 'it was not asked about, so it must not be taken for one Tebex never saw');
    }

    #[Test]
    public function a_refund_still_asked_for_after_the_mode_is_switched_off_goes_to_the_api_for_a_payment_made_through_it(): void
    {
        [$order, $payment] = $this->paidOrder('15.00');
        $refund = Refund::query()->create([
            'order_id' => $order->id,
            'payment_id' => $payment->id,
            'provider' => 'tebex',
            'idempotency_key' => 'again-1',
            'status' => RefundStatus::Pending,
            'amount' => '15.00',
            'currency' => 'USD',
        ]);

        config(['tebex.checkout_api.enabled' => false]);
        $outcome = app(TebexProvider::class)->refund($refund);

        $this->assertSame(RefundStatus::Succeeded, $outcome->status);
        $this->assertCount(1, $this->api->calls('refund'));
    }

    #[Test]
    public function a_payment_made_another_way_is_asked_about_through_the_api_only_while_the_mode_is_on(): void
    {
        [$order, $payment] = $this->paidOrder('15.00');
        $payment->forceFill(['raw' => ['nonce' => 'x']])->save();
        $this->api->refundInPanel(self::TRANSACTION, 2);
        $payment = $payment->fresh();

        config(['tebex.checkout_api.enabled' => false]);
        $asked = count($this->api->requests);
        app(TebexProvider::class)->syncRefunds($payment);

        $this->assertCount($asked, $this->api->requests, 'the Headless API cannot be asked: a refund there arrives as a delivery');
        $this->assertSame(0, Refund::query()->count());

        config(['tebex.checkout_api.enabled' => true]);
        app(TebexProvider::class)->syncRefunds($payment);

        $this->assertSame(1, Refund::query()->count(), 'with the mode on the API is the way to ask about any Tebex payment');
    }

    #[Test]
    public function a_refund_made_in_the_tebex_panel_is_found_when_the_payment_is_looked_at(): void
    {
        [$order, $payment] = $this->paidOrder('15.00');
        $this->api->refundInPanel(self::TRANSACTION, 2);

        app(TebexProvider::class)->syncRefunds($payment);

        $refund = Refund::query()->sole();
        $this->assertSame(RefundStatus::Succeeded, $refund->status);
        $this->assertSame(self::TRANSACTION.'-refund', $refund->provider_reference);
        $this->assertSame(OrderPaymentStatus::Refunded, $order->fresh()->payment_status);
    }

    #[Test]
    public function a_payment_that_has_not_been_refunded_changes_nothing_when_looked_at(): void
    {
        [$order, $payment] = $this->paidOrder('15.00');

        app(TebexProvider::class)->syncRefunds($payment);

        $this->assertSame(0, Refund::query()->count());
        $this->assertSame(OrderPaymentStatus::Paid, $order->fresh()->payment_status);
    }

    #[Test]
    public function the_order_page_offers_a_provider_refund_of_the_whole_payment_only(): void
    {
        [$order] = $this->paidOrder('15.00');

        $this->actingAs($this->admin())->get(route('acp.commerce.orders.show', $order))->assertInertia(fn (Assert $page) => $page
            ->where('refunding.via_provider', true)
            ->where('refunding.partial_refunds', false));

        config(['tebex.checkout_api.enabled' => false]);

        $this->actingAs($this->admin())->get(route('acp.commerce.orders.show', $order))->assertInertia(fn (Assert $page) => $page
            ->where('refunding.via_provider', false));
    }

    // --- Looking a payment up ------------------------------------------------------------------------------------------------------

    private function checkPayment(Order $order)
    {
        return $this->actingAs($this->admin())->post(route('acp.commerce.orders.check-payment', $order));
    }

    #[Test]
    public function checking_an_order_whose_delivery_was_lost_settles_it_from_what_tebex_holds(): void
    {
        [$order, $payment] = $this->placedOrder('15.00');
        $this->api->pay($payment->provider_reference, $this->tebexProductPaymentFor($order, $payment));
        $this->assertSame(OrderPaymentStatus::Unpaid, $order->fresh()->payment_status, 'the delivery never came');

        $this->checkPayment($order)->assertSessionHas('success');

        $order->refresh();
        $this->assertSame(OrderPaymentStatus::Paid, $order->payment_status);
        $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()->status);
        $this->assertSame(self::TRANSACTION, $payment->fresh()->provider_payment_id);
        $this->assertSame(1, DownloadGrant::query()->where('order_id', $order->id)->count(), 'delivered as for a payment that arrived by webhook');
    }

    #[Test]
    public function what_the_lookup_finds_is_checked_like_a_delivery_and_held_when_it_is_not_what_was_ordered(): void
    {
        [$order, $payment] = $this->placedOrder('15.00');
        $subject = $this->tebexProductPaymentFor($order, $payment);
        $subject['products'][0]['quantity'] = 3;
        $this->api->pay($payment->provider_reference, $subject);

        $this->checkPayment($order)->assertSessionHas('success');

        $this->assertSame(OrderPaymentStatus::Unpaid, $order->fresh()->payment_status);
        $this->assertSame(PaymentStatus::Review, $payment->fresh()->status);
    }

    #[Test]
    public function checking_an_order_nobody_has_paid_for_does_nothing(): void
    {
        [$order] = $this->placedOrder('15.00');

        $this->checkPayment($order)->assertSessionHas('success');

        $this->assertSame(OrderPaymentStatus::Unpaid, $order->fresh()->payment_status);
        $this->assertSame([], $this->api->calls('payment'), 'there is no payment to ask for');
    }

    #[Test]
    public function a_payment_that_is_not_complete_is_not_applied_by_a_lookup(): void
    {
        [$order, $payment] = $this->placedOrder('15.00');
        $this->api->pay($payment->provider_reference, $this->tebexProductPaymentFor($order, $payment, ['status' => ['id' => 18, 'description' => 'Declined']]));

        $this->checkPayment($order)->assertSessionHas('success');

        $this->assertSame(OrderPaymentStatus::Unpaid, $order->fresh()->payment_status);
        $this->assertSame(0, BillingWebhookCall::query()->count(), 'nothing was applied, so nothing is kept or flagged for staff to look at');
    }

    #[Test]
    public function the_delivery_that_arrives_after_a_lookup_changes_nothing(): void
    {
        [$order, $payment] = $this->placedOrder('15.00');
        $subject = $this->tebexProductPaymentFor($order, $payment);
        $this->api->pay($payment->provider_reference, $subject);
        $this->checkPayment($order);

        $this->webhook('payment.completed', $subject)->assertOk();

        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(1, $order->events()->where('type', OrderEvent::PAID)->count(), 'paid once');
        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
    }

    #[Test]
    public function a_payment_made_another_way_is_not_looked_up(): void
    {
        [$order, $payment] = $this->placedOrder('15.00');
        // One of Tebex's own packages (the Headless API): nothing can be asked of Tebex about it.
        $payment->forceFill(['raw' => ['nonce' => 'x']])->save();
        $asked = count($this->api->requests);

        $this->checkPayment($order)->assertSessionHas('success');

        $this->assertCount($asked, $this->api->requests, 'nothing more was asked of Tebex');
    }

    // --- The client --------------------------------------------------------------------------------------------------------------------

    #[Test]
    public function a_refund_is_not_repeated_after_a_server_error_but_a_payment_lookup_is(): void
    {
        $subject = $this->tebexProductPaymentFor(Order::factory()->create(), Payment::factory()->create());
        $this->api->payments[self::TRANSACTION] = $subject;
        $client = app(TebexCheckoutClient::class);

        $this->api->failOn('refund', 500, 3);
        try {
            $client->refund(self::TRANSACTION);
            $this->fail('A server error was not reported.');
        } catch (TebexException) {
            $this->assertCount(1, $this->api->calls('refund'));
        }

        $this->api->failOn('payment', 500, 1);
        $this->assertSame(self::TRANSACTION, $client->payment(self::TRANSACTION)['transaction_id']);
        $this->assertCount(2, $this->api->calls('payment'));
    }

    #[Test]
    public function an_answer_that_is_not_a_payment_is_refused(): void
    {
        $this->api->payments[self::TRANSACTION] = ['status' => ['id' => 1]];

        $this->expectException(TebexException::class);

        app(TebexCheckoutClient::class)->payment(self::TRANSACTION);
    }

    // --- The ACP page ------------------------------------------------------------------------------------------------------------------

    #[Test]
    public function the_tebex_page_shows_the_checkout_api_and_not_the_catalogue(): void
    {
        $response = $this->actingAs($this->admin())->get(route('acp.commerce.tebex.index'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('mode', 'checkout_api')
            ->where('checkout_api.configured', true)
            ->where('checkout_api.project_id', '…456')
            ->where('checkout_api.has_private_key', true));

        $this->assertStringNotContainsString('private-key-that-must-never-be-shown', $response->getContent());
    }

    #[Test]
    public function the_tebex_page_is_about_packages_while_the_checkout_api_is_off(): void
    {
        config(['tebex.checkout_api.enabled' => false]);

        $this->actingAs($this->admin())->get(route('acp.commerce.tebex.index'))->assertInertia(fn (Assert $page) => $page->where('mode', 'packages'));
    }

    #[Test]
    public function the_checkout_api_can_be_tested_and_a_basket_that_does_not_exist_means_the_keys_were_accepted(): void
    {
        $this->actingAs($this->admin())->post(route('acp.commerce.tebex.test'))
            ->assertSessionHas('tebex_test', ['ok' => true, 'checkout_api' => true]);

        $this->assertCount(1, $this->api->calls('get'));
        $this->assertStringEndsWith('/baskets/connection-test', $this->api->calls('get')[0]['url']);
    }

    #[Test]
    public function keys_tebex_refuses_are_reported_by_the_test(): void
    {
        $this->api->failOn('get', 401);

        $this->actingAs($this->admin())->post(route('acp.commerce.tebex.test'))
            ->assertSessionHas('tebex_test', fn (array $result) => $result['ok'] === false && str_contains($result['message'], 'approved the Checkout API'));
    }

    #[Test]
    public function there_is_no_catalogue_to_refresh_while_the_shop_sells_its_own_products(): void
    {
        $this->actingAs($this->admin())->post(route('acp.commerce.tebex.sync'))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'not used while the Checkout API mode is on'));

        $this->assertSame(0, TebexSync::query()->count());
        $this->assertSame([], $this->api->requests);
        $this->artisan('tebex:sync')->assertFailed();
    }

    #[Test]
    public function the_scheduler_does_not_refresh_a_catalogue_that_is_not_used(): void
    {
        config(['tebex.public_token' => 'spk3-'.str_repeat('a', 24), 'tebex.sync.every_minutes' => 60]);

        $this->assertFalse(app(TebexCatalogue::class)->isDue(), 'even with a public token left in the environment');

        config(['tebex.checkout_api.enabled' => false]);

        $this->assertTrue(app(TebexCatalogue::class)->isDue());
    }
}
