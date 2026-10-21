<?php

namespace Tests\Feature\Tebex;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\BillingWebhookCall;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Payment;
use App\Models\ProviderSubscription;
use App\Models\Refund;
use App\Models\TebexPackage;
use App\Models\User;
use App\Support\Commerce\GuestCarryOver;
use App\Support\Commerce\OrderRefunder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeTebexBaskets;
use Tests\Support\InteractsWithCommerce;
use Tests\Support\InteractsWithTebex;
use Tests\Support\InteractsWithTebexWebhooks;
use Tests\TestCase;

/**
 * Recurring packages: what Tebex says about a recurring payment is mirrored, and each renewal is recorded as a payment of
 * the order that started it. The deliveries are shaped like the ones Tebex's documentation shows
 * (`tests/Fixtures/tebex/recurring-payment.json`); that real ones are the same is the live check's job (M2b).
 */
class TebexRecurringTest extends TestCase
{
    use InteractsWithCommerce;
    use InteractsWithTebex;
    use InteractsWithTebexWebhooks;
    use RefreshDatabase;

    private const REFERENCE = 'tbx-r-4a1b2c';

    private const FIRST = 'tbx-4a1b2c3d4e5f';

    private const RENEWAL = 'tbx-renewal-0001';

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
     * Check out a cart of the monthly package through the real checkout, as a buyer would.
     *
     * @return array{0: Order, 1: Payment, 2: TebexPackage}
     */
    private function placeOrder(int $quantity = 1): array
    {
        $package = TebexPackage::factory()->subscription()->priced('4.50')->create(['ident' => 'mvp-monthly', 'name' => 'MVP (monthly)']);

        $this->post(route('shop.cart.packages.store'), ['package_id' => $package->tebex_id, 'quantity' => $quantity])->assertSessionHas('success');
        $this->post(route('shop.checkout.store'), ['email' => 'buyer@example.com', 'name' => 'Ada Buyer', 'token' => (string) Str::uuid()])->assertRedirect();

        $order = Order::query()->latest('id')->firstOrFail();

        return [$order, $order->payments()->firstOrFail(), $package];
    }

    /**
     * The first payment of the recurring package, settled the way Tebex does: complete, and naming the recurring payment.
     *
     * @return array{0: Order, 1: Payment, 2: TebexPackage}
     */
    private function start(int $quantity = 1): array
    {
        [$order, $payment, $package] = $this->placeOrder($quantity);

        $this->deliver('payment.completed', $this->tebexPaymentFor($order, $payment, ['recurring_payment_reference' => self::REFERENCE]))->assertOk();

        return [$order->fresh(), $payment->fresh(), $package];
    }

    /**
     * @param  array<string, mixed>  $subject
     */
    private function deliver(string $type, array $subject, ?string $id = null, ?string $date = null)
    {
        return $this->deliverToTebexEndpoint($this->tebexDelivery($type, $subject, $id, $date));
    }

    /**
     * A `recurring-payment.*` delivery about the order's recurring payment.
     *
     * @param  array<string, mixed>  $subject
     */
    private function recurring(string $type, Order $order, Payment $payment, array $subject = [], ?string $date = null, ?string $id = null)
    {
        return $this->deliver($type, $this->tebexRecurringFor($order, $payment, self::REFERENCE, $subject), $id, $date);
    }

    /**
     * A renewal: a later payment of the same recurring payment (its own transaction, the order's id and nonce on it).
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function renewal(Order $order, Payment $payment, string $transaction = self::RENEWAL, array $changes = []): array
    {
        return $this->tebexPaymentFor($order, $payment, ['transaction_id' => $transaction, 'recurring_payment_reference' => self::REFERENCE, ...$changes]);
    }

    private function subscription(): ProviderSubscription
    {
        return ProviderSubscription::query()->sole();
    }

    private function notes(Order $order): array
    {
        return $order->events()->where('type', OrderEvent::NOTE)->orderBy('id')->pluck('message')->all();
    }

    // --- The first payment -------------------------------------------------------------------------------------------

    #[Test]
    public function the_first_payment_settles_the_order_and_starts_keeping_track_of_the_recurring_payment(): void
    {
        [$order, $payment, $package] = $this->start();

        $this->assertSame(OrderPaymentStatus::Paid, $order->payment_status);
        $this->assertSame(OrderStatus::Completed, $order->status);

        $subscription = $this->subscription();
        $this->assertSame('tebex', $subscription->provider);
        $this->assertSame(self::REFERENCE, $subscription->reference);
        $this->assertSame($order->id, $subscription->order_id);
        $this->assertSame($package->id, $subscription->tebex_package_id);
        $this->assertSame('MVP (monthly)', $subscription->package_name);
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertSame('4.50', $subscription->amount);
        $this->assertSame('USD', $subscription->currency);
        $this->assertSame(0, $subscription->renewals_count);
        $this->assertNotNull($subscription->started_at);
        $this->assertNotNull($subscription->last_payment_at);
        $this->assertNull($subscription->ended_at);
        $this->assertTrue($subscription->isLive());
        $this->assertNull($subscription->owner_type, 'a guest order has no owner yet');
    }

    #[Test]
    public function the_recurring_payment_belongs_to_whoever_owns_the_order(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->start();

        $this->assertTrue($this->subscription()->isOwnedBy($user));
    }

    #[Test]
    public function a_one_off_payment_makes_no_recurring_payment(): void
    {
        [$order, $payment] = $this->placeOrder();

        $this->deliver('payment.completed', $this->tebexPaymentFor($order, $payment))->assertOk();

        $this->assertSame(0, ProviderSubscription::query()->count());
    }

    #[Test]
    public function the_first_payment_delivered_again_changes_nothing(): void
    {
        [$order, $payment] = $this->start();

        $this->deliver('payment.completed', $this->tebexPaymentFor($order, $payment, ['recurring_payment_reference' => self::REFERENCE]))->assertOk();

        $this->assertSame(1, ProviderSubscription::query()->count());
        $this->assertSame(0, $this->subscription()->renewals_count);
        $this->assertSame(1, Payment::query()->count());
    }

    // --- What Tebex says about it ------------------------------------------------------------------------------------

    #[Test]
    public function the_started_delivery_fills_in_what_tebex_says(): void
    {
        [$order, $payment] = $this->start();

        $this->recurring('recurring-payment.started', $order, $payment, ['next_payment_at' => '2026-11-21T11:34:13.000000Z', 'price' => ['amount' => 5.0, 'currency' => 'USD']])->assertOk();

        $subscription = $this->subscription();
        $this->assertSame(1, ProviderSubscription::query()->count());
        $this->assertSame('5.00', $subscription->amount);
        $this->assertSame('2026-11-21', $subscription->next_payment_at->format('Y-m-d'));
        $this->assertSame('Active', $subscription->provider_status);
        $this->assertNotNull($subscription->last_event_at);
        $this->assertStringContainsString('was started at Tebex', $this->notes($order)[0]);
    }

    #[Test]
    public function a_recurring_payment_that_is_announced_before_its_payment_is_tied_to_the_order_all_the_same(): void
    {
        [$order, $payment] = $this->placeOrder();

        $this->recurring('recurring-payment.started', $order, $payment, ['price' => ['amount' => 5.0, 'currency' => 'USD']])->assertOk();

        $this->assertSame($order->id, $this->subscription()->order_id);
        $this->assertSame(OrderPaymentStatus::Unpaid, $order->fresh()->payment_status, 'announcing it settles nothing');

        $this->deliver('payment.completed', $this->tebexPaymentFor($order, $payment, ['recurring_payment_reference' => self::REFERENCE]))->assertOk();

        $this->assertSame(1, ProviderSubscription::query()->count());
        $this->assertSame(OrderPaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertSame(0, $this->subscription()->renewals_count, 'the first payment is not a renewal');
        $this->assertSame('5.00', $this->subscription()->amount, 'what Tebex charges each time is what it said, not what the first payment came to');
    }

    #[Test]
    public function a_failed_renewal_makes_it_overdue_but_not_over(): void
    {
        [$order, $payment] = $this->start();

        $this->recurring('recurring-payment.renewed', $order, $payment, ['status' => ['id' => 3, 'description' => 'Overdue'], 'fail_count' => 2])->assertOk();

        $subscription = $this->subscription();
        $this->assertSame(SubscriptionStatus::Overdue, $subscription->status);
        $this->assertSame(2, $subscription->fail_count);
        $this->assertTrue($subscription->isLive(), 'Tebex tries again');
        $this->assertStringContainsString('could not be taken (2 tries)', $this->notes($order)[0]);
    }

    #[Test]
    public function it_ends_when_tebex_says_it_has_and_is_then_over(): void
    {
        [$order, $payment] = $this->start();

        $this->recurring('recurring-payment.ended', $order, $payment, ['status' => ['id' => 5, 'description' => 'Cancelled'], 'cancelled_at' => '2026-12-01T10:00:00.000000Z', 'cancel_reason' => 'Cancelled by webstore on request', 'next_payment_at' => null], '2026-12-01T10:00:01+00:00')->assertOk();

        $subscription = $this->subscription();
        $this->assertSame(SubscriptionStatus::Cancelled, $subscription->status);
        $this->assertSame('2026-12-01', $subscription->ended_at->format('Y-m-d'));
        $this->assertSame('2026-12-01', $subscription->cancelled_at->format('Y-m-d'));
        $this->assertSame('Cancelled by webstore on request', $subscription->cancel_reason);
        $this->assertNull($subscription->next_payment_at);
        $this->assertFalse($subscription->isLive());
        $this->assertStringContainsString('has ended', $this->notes($order)[0]);
    }

    #[Test]
    public function the_moment_it_ended_is_kept_when_it_is_said_again(): void
    {
        [$order, $payment] = $this->start();

        $this->recurring('recurring-payment.ended', $order, $payment, ['status' => ['id' => 4, 'description' => 'Expired']], '2026-12-01T10:00:00+00:00')->assertOk();
        $this->recurring('recurring-payment.ended', $order, $payment, ['status' => ['id' => 4, 'description' => 'Expired']], '2026-12-05T10:00:00+00:00')->assertOk();

        $this->assertSame('2026-12-01', $this->subscription()->ended_at->format('Y-m-d'));
        $this->assertSame(SubscriptionStatus::Expired, $this->subscription()->status);
    }

    #[Test]
    public function asking_to_cancel_does_not_end_it_and_taking_the_request_back_renews_it_as_normal(): void
    {
        [$order, $payment] = $this->start();

        $this->recurring('recurring-payment.cancellation.requested', $order, $payment, ['cancel_reason' => 'Customer asked'], '2026-11-01T10:00:00+00:00')->assertOk();

        $subscription = $this->subscription();
        $this->assertNotNull($subscription->cancellation_requested_at);
        $this->assertTrue($subscription->isLive(), 'it runs until the end of what was paid for');
        $this->assertNull($subscription->ended_at);
        $this->assertStringContainsString('Cancelling the recurring payment', $this->notes($order)[0]);

        $this->recurring('recurring-payment.cancellation.aborted', $order, $payment, [], '2026-11-02T10:00:00+00:00')->assertOk();

        $this->assertNull($this->subscription()->cancellation_requested_at);
        $this->assertStringContainsString('taken back', $this->notes($order)[1]);
    }

    #[Test]
    public function the_status_changing_is_mirrored_whatever_the_event(): void
    {
        [$order, $payment] = $this->start();

        $this->recurring('recurring-payment.status-changed', $order, $payment, ['status' => ['id' => 4, 'description' => 'Expired']], '2026-11-01T10:00:00+00:00')->assertOk();

        $this->assertSame(SubscriptionStatus::Expired, $this->subscription()->status);
        $this->assertFalse($this->subscription()->isLive(), 'expired is over even before Tebex says it ended');
    }

    #[Test]
    public function a_pending_downgrade_is_still_active_and_a_status_the_shop_does_not_know_changes_nothing(): void
    {
        [$order, $payment] = $this->start();

        $this->recurring('recurring-payment.status-changed', $order, $payment, ['status' => ['id' => 3, 'description' => 'Overdue']], '2026-11-01T10:00:00+00:00')->assertOk();
        $this->assertSame(SubscriptionStatus::Overdue, $this->subscription()->status);

        $this->recurring('recurring-payment.status-changed', $order, $payment, ['status' => ['id' => 7, 'description' => 'Pending Downgrade']], '2026-11-02T10:00:00+00:00')->assertOk();
        $this->assertSame(SubscriptionStatus::Active, $this->subscription()->status);
        $this->assertSame('Pending Downgrade', $this->subscription()->provider_status);

        $this->recurring('recurring-payment.status-changed', $order, $payment, ['status' => ['id' => 3, 'description' => 'Overdue']], '2026-11-03T10:00:00+00:00')->assertOk();
        $this->recurring('recurring-payment.status-changed', $order, $payment, ['status' => ['id' => 99, 'description' => 'Something new']], '2026-11-04T10:00:00+00:00')->assertOk();

        $this->assertSame(SubscriptionStatus::Overdue, $this->subscription()->status, 'kept, not guessed at');
        $this->assertSame('Something new', $this->subscription()->provider_status, 'but what Tebex called it is kept');
    }

    #[Test]
    public function a_delivery_older_than_one_already_applied_is_ignored(): void
    {
        [$order, $payment] = $this->start();

        $this->recurring('recurring-payment.ended', $order, $payment, ['status' => ['id' => 5, 'description' => 'Cancelled']], '2026-12-01T10:00:00+00:00')->assertOk();
        $before = $this->subscription();

        // Tebex retries, and nothing promises the order deliveries arrive in: this one is from before the end.
        $this->recurring('recurring-payment.renewed', $order, $payment, ['status' => ['id' => 2, 'description' => 'Active'], 'next_payment_at' => '2027-01-01T00:00:00.000000Z'], '2026-11-20T10:00:00+00:00')->assertOk();

        $after = $this->subscription();
        $this->assertSame(SubscriptionStatus::Cancelled, $after->status);
        $this->assertNull($after->next_payment_at);
        $this->assertSame($before->last_event_at->toIso8601String(), $after->last_event_at->toIso8601String());
    }

    #[Test]
    public function a_delivery_with_no_date_is_applied(): void
    {
        [$order, $payment] = $this->start();
        $this->recurring('recurring-payment.renewed', $order, $payment, ['fail_count' => 1], '2026-11-01T10:00:00+00:00')->assertOk();

        $body = $this->tebexDelivery('recurring-payment.renewed', $this->tebexRecurringFor($order, $payment, self::REFERENCE, ['fail_count' => 3]));
        unset($body['date']);
        $this->deliverToTebexEndpoint($body)->assertOk();

        $this->assertSame(3, $this->subscription()->fail_count);
    }

    #[Test]
    public function a_recurring_delivery_that_names_no_shop_order_is_not_kept_and_says_so(): void
    {
        [$order, $payment] = $this->placeOrder();
        $subject = $this->tebexRecurringFor($order, $payment, 'tbx-r-elsewhere');
        $subject['initial_payment']['products'][0]['custom'] = null;
        $subject['last_payment']['products'][0]['custom'] = null;

        $this->deliver('recurring-payment.started', $subject)->assertOk();

        $this->assertSame(0, ProviderSubscription::query()->count());
        $this->assertStringContainsString('names no shop order', (string) BillingWebhookCall::query()->sole()->error);
    }

    #[Test]
    public function a_recurring_delivery_with_no_reference_is_not_applied(): void
    {
        [$order, $payment] = $this->start();
        $subject = $this->tebexRecurringFor($order, $payment);
        unset($subject['reference']);

        $this->deliver('recurring-payment.renewed', $subject)->assertOk();

        $this->assertStringContainsString('no reference', (string) BillingWebhookCall::query()->where('type', 'recurring-payment.renewed')->sole()->error);
        $this->assertSame(0, $this->subscription()->fail_count);
    }

    #[Test]
    public function the_same_delivery_sent_again_notes_it_once(): void
    {
        [$order, $payment] = $this->start();

        $this->recurring('recurring-payment.ended', $order, $payment, ['status' => ['id' => 5, 'description' => 'Cancelled']], null, 'evt-1')->assertOk();
        $this->recurring('recurring-payment.ended', $order, $payment, ['status' => ['id' => 5, 'description' => 'Cancelled']], null, 'evt-1')->assertOk();

        $this->assertCount(1, $this->notes($order));
    }

    #[Test]
    public function what_is_kept_of_a_delivery_holds_no_personal_details(): void
    {
        [$order, $payment] = $this->start();

        $this->recurring('recurring-payment.started', $order, $payment)->assertOk();

        $kept = (string) json_encode(ProviderSubscription::query()->sole()->makeVisible('raw')->raw);
        foreach (['ada.private@example.test', '203.0.113.7', 'TE57 1NG'] as $private) {
            $this->assertStringNotContainsString($private, $kept);
        }
    }

    // --- Renewals ------------------------------------------------------------------------------------------------------

    #[Test]
    public function a_renewal_is_recorded_as_a_payment_of_the_order_and_does_not_pay_it_again(): void
    {
        [$order, $payment] = $this->start();
        $paidAt = $order->paid_at;
        $refundable = app(OrderRefunder::class)->refundable($order);

        $this->deliver('payment.completed', $this->renewal($order, $payment))->assertOk();

        $renewal = Payment::query()->where('provider_reference', self::RENEWAL)->sole();
        $this->assertSame($order->id, $renewal->order_id);
        $this->assertSame(PaymentStatus::Succeeded, $renewal->status);
        $this->assertSame('4.50', $renewal->amount);
        $this->assertSame('USD', $renewal->currency);
        $this->assertSame(self::RENEWAL, $renewal->provider_payment_id);
        $this->assertNotNull($renewal->paid_at);
        $this->assertTrue($renewal->raw['renewal']);
        $this->assertSame(self::REFERENCE, $renewal->raw['recurring_payment_reference']);

        $order->refresh();
        $this->assertSame(OrderPaymentStatus::Paid, $order->payment_status);
        $this->assertSame('4.50', $order->grand_total, 'what the order took in is what it took the first time');
        $this->assertTrue($paidAt->equalTo($order->paid_at));
        $this->assertSame(1, $order->events()->where('type', OrderEvent::PAID)->count());
        $this->assertTrue(app(OrderRefunder::class)->refundable($order)->equals($refundable), 'a renewal is not part of what the order can have refunded');
        $this->assertStringContainsString('A renewal payment of 4.50 USD', $this->notes($order)[0]);

        $subscription = $this->subscription();
        $this->assertSame(1, $subscription->renewals_count);
        $this->assertNotNull($subscription->last_payment_at);
    }

    #[Test]
    public function a_renewal_belongs_to_whoever_owns_the_order(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        [$order, $payment] = $this->start();

        $this->deliver('payment.completed', $this->renewal($order, $payment))->assertOk();

        $this->assertTrue(Payment::query()->where('provider_reference', self::RENEWAL)->sole()->isOwnedBy($user));
    }

    #[Test]
    public function a_renewal_delivered_again_is_recorded_once(): void
    {
        [$order, $payment] = $this->start();
        $subject = $this->renewal($order, $payment);

        $this->deliver('payment.completed', $subject, 'evt-renewal')->assertOk();
        $this->deliver('payment.completed', $subject, 'evt-renewal')->assertOk();
        $this->deliver('payment.completed', $subject)->assertOk();

        $this->assertSame(2, Payment::query()->count(), 'the order\'s own payment and the renewal');
        $this->assertSame(1, $this->subscription()->renewals_count);
        $this->assertCount(1, $this->notes($order));
    }

    #[Test]
    public function each_renewal_is_a_payment_of_its_own(): void
    {
        [$order, $payment] = $this->start();

        $this->deliver('payment.completed', $this->renewal($order, $payment, 'tbx-renewal-0001'))->assertOk();
        $this->deliver('payment.completed', $this->renewal($order, $payment, 'tbx-renewal-0002'))->assertOk();

        $this->assertSame(3, Payment::query()->count());
        $this->assertSame(2, $this->subscription()->renewals_count);
        $this->assertSame(OrderPaymentStatus::Paid, $order->fresh()->payment_status);
    }

    #[Test]
    public function a_renewal_is_found_through_the_recurring_payment_when_it_carries_no_order_of_its_own(): void
    {
        [$order, $payment] = $this->start();
        $subject = $this->renewal($order, $payment);
        $subject['products'][0]['custom'] = null;

        $this->deliver('payment.completed', $subject)->assertOk();

        $this->assertSame(1, $this->subscription()->renewals_count);
        $this->assertSame(PaymentStatus::Succeeded, Payment::query()->where('provider_reference', self::RENEWAL)->sole()->status);
    }

    #[Test]
    public function a_renewal_of_a_recurring_payment_the_shop_does_not_know_and_that_names_no_order_is_left_alone(): void
    {
        [$order, $payment] = $this->start();
        $subject = $this->renewal($order, $payment, self::RENEWAL, ['recurring_payment_reference' => 'tbx-r-unknown']);
        $subject['products'][0]['custom'] = null;

        $this->deliver('payment.completed', $subject)->assertOk();

        $this->assertSame(1, Payment::query()->count());
        $this->assertStringContainsString('names no order', (string) BillingWebhookCall::query()->latest('id')->first()->error);
    }

    #[Test]
    public function a_renewal_for_other_packages_than_the_order_holds_is_held_for_review(): void
    {
        [$order, $payment] = $this->start();
        $other = TebexPackage::factory()->priced('1.00')->create(['ident' => 'cheap-thing']);
        $subject = $this->renewal($order, $payment);
        $subject['products'][0]['id'] = $other->tebex_id;
        Log::spy();

        $this->deliver('payment.completed', $subject)->assertOk();

        $renewal = Payment::query()->where('provider_reference', self::RENEWAL)->sole();
        $this->assertSame(PaymentStatus::Review, $renewal->status);
        $this->assertNull($renewal->paid_at);
        $this->assertSame(0, $this->subscription()->renewals_count);
        $this->assertCount(0, $this->notes($order));
        $this->assertStringContainsString('recorded for review', (string) BillingWebhookCall::query()->latest('id')->first()->error);
        Log::shouldHaveReceived('critical')->once();

        // Come again, it is still held, not turned into a payment.
        $this->deliver('payment.completed', $subject)->assertOk();
        $this->assertSame(PaymentStatus::Review, $renewal->fresh()->status);
        $this->assertSame(1, Payment::query()->where('provider_reference', self::RENEWAL)->count());
    }

    #[Test]
    public function a_renewal_in_another_currency_or_with_no_amount_or_no_packages_is_held_too(): void
    {
        [$order, $payment] = $this->start();

        $pounds = $this->renewal($order, $payment, 'tbx-renewal-gbp', ['price_paid' => ['amount' => 4.5, 'currency' => 'GBP']]);
        $noAmount = $this->renewal($order, $payment, 'tbx-renewal-none');
        unset($noAmount['price_paid'], $noAmount['price']);
        $noProducts = $this->renewal($order, $payment, 'tbx-renewal-bare');
        $noProducts['products'] = [];
        $noProducts['custom'] = ['order' => $order->public_id, 'nonce' => $payment->raw['nonce']];

        foreach ([$pounds, $noAmount, $noProducts] as $subject) {
            $this->deliver('payment.completed', $subject)->assertOk();
        }

        $this->assertSame(3, Payment::query()->where('status', PaymentStatus::Review->value)->count());
        $this->assertSame(0, $this->subscription()->renewals_count);
    }

    #[Test]
    public function a_renewal_may_list_less_than_the_order_holds_but_never_more(): void
    {
        $extra = TebexPackage::factory()->priced('3.00')->create(['ident' => 'pet-cat', 'name' => 'Pet cat']);
        $this->post(route('shop.cart.packages.store'), ['package_id' => $extra->tebex_id, 'quantity' => 1]);
        [$order, $payment, $package] = $this->start();
        $this->assertCount(2, $order->items);

        // Only the recurring package is renewed.
        $recurringOnly = $this->renewal($order, $payment, 'tbx-renewal-ok');
        $recurringOnly['products'] = [$recurringOnly['products'][1]];
        $recurringOnly['products'][0]['custom'] = ['order' => $order->public_id, 'nonce' => $payment->raw['nonce']];
        $this->deliver('payment.completed', $recurringOnly)->assertOk();
        $this->assertSame(PaymentStatus::Succeeded, Payment::query()->where('provider_reference', 'tbx-renewal-ok')->sole()->status);

        // Two of it, when one was ordered, is not what the order holds.
        $tooMany = $this->renewal($order, $payment, 'tbx-renewal-many');
        $tooMany['products'] = [[...$recurringOnly['products'][0], 'quantity' => 2]];
        $this->deliver('payment.completed', $tooMany)->assertOk();
        $this->assertSame(PaymentStatus::Review, Payment::query()->where('provider_reference', 'tbx-renewal-many')->sole()->status);
    }

    #[Test]
    public function a_payment_that_is_not_recurring_for_an_order_already_settled_is_still_not_applied(): void
    {
        [$order, $payment] = $this->start();
        $subject = $this->tebexPaymentFor($order, $payment, ['transaction_id' => 'tbx-another']);

        $this->deliver('payment.completed', $subject)->assertOk();

        $this->assertSame(1, Payment::query()->count());
        $this->assertStringContainsString('not a renewal', (string) BillingWebhookCall::query()->latest('id')->first()->error);
    }

    // --- A renewal given back ------------------------------------------------------------------------------------------

    #[Test]
    public function a_renewal_that_is_refunded_is_noted_and_the_order_is_not_refunded(): void
    {
        [$order, $payment] = $this->start();
        $this->deliver('payment.completed', $this->renewal($order, $payment))->assertOk();

        $this->deliver('payment.refunded', $this->renewal($order, $payment, self::RENEWAL, ['status' => ['id' => 2, 'description' => 'Refund']]))->assertOk();

        $order->refresh();
        $this->assertSame(0, Refund::query()->count());
        $this->assertSame('0.00', $order->refunded_total);
        $this->assertSame(OrderPaymentStatus::Paid, $order->payment_status);
        $this->assertSame('was refunded', Payment::query()->where('provider_reference', self::RENEWAL)->sole()->raw['returned']);
        $this->assertStringContainsString('was refunded at Tebex', $this->notes($order)[1]);

        // Sent again, it says nothing twice.
        $this->deliver('payment.refunded', $this->renewal($order, $payment, self::RENEWAL, ['status' => ['id' => 2, 'description' => 'Refund']]))->assertOk();
        $this->assertCount(2, $this->notes($order));
    }

    #[Test]
    public function a_renewal_whose_refund_is_pending_is_noted_as_it_goes_through(): void
    {
        [$order, $payment] = $this->start();
        $this->deliver('payment.completed', $this->renewal($order, $payment))->assertOk();

        $this->deliver('payment.refunded', $this->renewal($order, $payment, self::RENEWAL, ['status' => ['id' => 21, 'description' => 'Refund Pending']]))->assertOk();
        $this->deliver('payment.refunded', $this->renewal($order, $payment, self::RENEWAL, ['status' => ['id' => 2, 'description' => 'Refund']]))->assertOk();

        $notes = $this->notes($order);
        $this->assertStringContainsString('is being refunded', $notes[1]);
        $this->assertStringContainsString('was refunded', $notes[2]);
        $this->assertSame(0, Refund::query()->count());
    }

    #[Test]
    public function a_renewal_taken_in_a_lost_dispute_is_noted_and_the_order_is_not_refunded(): void
    {
        [$order, $payment] = $this->start();
        $this->deliver('payment.completed', $this->renewal($order, $payment))->assertOk();
        Log::spy();

        $this->deliver('payment.dispute.lost', $this->renewal($order, $payment, self::RENEWAL, ['status' => ['id' => 3, 'description' => 'Chargeback']]))->assertOk();

        $this->assertSame(0, Refund::query()->count());
        $this->assertSame(OrderPaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertStringContainsString('taken back in a dispute that was lost', implode(' ', $this->notes($order)));
        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    #[Test]
    public function the_first_payment_of_a_recurring_package_that_is_refunded_still_refunds_the_order(): void
    {
        [$order, $payment] = $this->start();

        $this->deliver('payment.refunded', $this->tebexPaymentFor($order, $payment, ['recurring_payment_reference' => self::REFERENCE, 'status' => ['id' => 2, 'description' => 'Refund']]))->assertOk();

        $this->assertSame(1, Refund::query()->count());
        $this->assertSame(OrderPaymentStatus::Refunded, $order->fresh()->payment_status);
    }

    // --- The mirror ------------------------------------------------------------------------------------------------------

    #[Test]
    public function a_guest_order_claimed_by_an_account_brings_its_recurring_payment_with_it(): void
    {
        [$order] = $this->start();
        $user = User::factory()->create(['email' => 'buyer@example.com']);
        $user->forceFill(['email_verified_at' => now()])->save();
        $this->assertNull($this->subscription()->owner_type);

        $claimed = app(GuestCarryOver::class)->claimOrders($user);

        $this->assertSame(1, $claimed);
        $this->assertTrue($this->subscription()->isOwnedBy($user));
        $this->assertTrue($order->fresh()->isOwnedBy($user));
    }

    // --- What the pages show -------------------------------------------------------------------------------------------

    #[Test]
    public function the_customers_order_page_says_where_the_recurring_payment_stands_without_what_only_staff_need(): void
    {
        [$order, $payment] = $this->start();
        $this->recurring('recurring-payment.started', $order, $payment, ['next_payment_at' => '2099-11-21T11:34:13.000000Z'])->assertOk();
        $this->deliver('payment.completed', $this->renewal($order, $payment))->assertOk();

        $this->get(URL::signedRoute('shop.checkout.complete', ['order' => $order->public_id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('commerce/CheckoutComplete')
                ->has('subscriptions', 1)
                ->where('subscriptions.0.package', 'MVP (monthly)')
                ->where('subscriptions.0.state', 'active')
                ->where('subscriptions.0.state_label', 'Active')
                ->where('subscriptions.0.live', true)
                ->where('subscriptions.0.amount', '4.50')
                ->where('subscriptions.0.currency', 'USD')
                ->where('subscriptions.0.renewals', 1)
                ->where('subscriptions.0.order_number', $order->number)
                ->whereNot('subscriptions.0.next_payment_at', null)
                ->missing('subscriptions.0.reference')
                ->missing('subscriptions.0.provider_status')
                ->missing('subscriptions.0.cancel_reason'));
    }

    #[Test]
    public function an_order_that_started_no_recurring_payment_lists_none(): void
    {
        [$order, $payment] = $this->placeOrder();
        $this->deliver('payment.completed', $this->tebexPaymentFor($order, $payment))->assertOk();

        $this->get(URL::signedRoute('shop.checkout.complete', ['order' => $order->public_id]))
            ->assertInertia(fn (Assert $page) => $page->has('subscriptions', 0));
    }

    #[Test]
    public function a_recurring_payment_that_was_asked_to_stop_says_so_and_one_that_ended_says_nothing_is_coming(): void
    {
        [$order, $payment] = $this->start();
        $complete = URL::signedRoute('shop.checkout.complete', ['order' => $order->public_id]);

        $this->recurring('recurring-payment.cancellation.requested', $order, $payment, ['next_payment_at' => '2099-11-21T11:34:13.000000Z'], '2026-11-01T10:00:00+00:00')->assertOk();
        $this->get($complete)->assertInertia(fn (Assert $page) => $page
            ->where('subscriptions.0.state', 'cancelling')
            ->where('subscriptions.0.live', true)
            ->whereNot('subscriptions.0.next_payment_at', null));

        $this->recurring('recurring-payment.ended', $order, $payment, ['status' => ['id' => 5, 'description' => 'Cancelled'], 'next_payment_at' => '2099-11-21T11:34:13.000000Z'], '2026-12-01T10:00:00+00:00')->assertOk();
        $this->get($complete)->assertInertia(fn (Assert $page) => $page
            ->where('subscriptions.0.state', 'ended')
            ->where('subscriptions.0.state_label', 'Cancelled')
            ->where('subscriptions.0.live', false)
            ->where('subscriptions.0.next_payment_at', null)
            ->whereNot('subscriptions.0.ended_at', null));
    }

    #[Test]
    public function an_overdue_one_is_shown_as_overdue(): void
    {
        [$order, $payment] = $this->start();

        $this->recurring('recurring-payment.renewed', $order, $payment, ['status' => ['id' => 3, 'description' => 'Overdue'], 'fail_count' => 1])->assertOk();

        $this->get(URL::signedRoute('shop.checkout.complete', ['order' => $order->public_id]))->assertInertia(fn (Assert $page) => $page
            ->where('subscriptions.0.state', 'overdue')
            ->where('subscriptions.0.state_label', 'Payment overdue')
            ->where('subscriptions.0.live', true));
    }

    #[Test]
    public function a_customer_sees_their_own_recurring_payments_and_nobody_elses(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        [$order] = $this->start();
        ProviderSubscription::factory()->create(['reference' => 'tbx-r-strangers', 'package_name' => 'Someone else\'s']);

        $this->get(route('shop.orders'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('commerce/Orders')
                ->has('subscriptions', 1)
                ->where('subscriptions.0.package', 'MVP (monthly)')
                ->where('subscriptions.0.order_number', $order->number)
                ->missing('subscriptions.0.reference'));
    }

    #[Test]
    public function a_customer_with_a_recurring_payment_from_a_guest_order_they_claimed_sees_it_in_their_orders(): void
    {
        [$order] = $this->start();
        $user = User::factory()->create(['email' => 'buyer@example.com']);
        $user->forceFill(['email_verified_at' => now()])->save();
        app(GuestCarryOver::class)->claimOrders($user);

        $this->actingAs($user)->get(route('shop.orders'))
            ->assertInertia(fn (Assert $page) => $page->has('subscriptions', 1)->where('subscriptions.0.order_number', $order->number));
    }

    #[Test]
    public function staff_see_the_recurring_payment_with_what_tebex_said_and_the_renewals_among_the_payments(): void
    {
        [$order, $payment] = $this->start();
        $this->recurring('recurring-payment.renewed', $order, $payment, ['status' => ['id' => 3, 'description' => 'Overdue'], 'fail_count' => 2, 'cancel_reason' => 'Card declined'])->assertOk();
        $this->deliver('payment.completed', $this->renewal($order, $payment))->assertOk();
        $this->seed(RolePermissionSeeder::class);

        $this->actingAs(User::factory()->create()->assignRole('admin'))
            ->get(route('acp.commerce.orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('subscriptions', 1)
                ->where('subscriptions.0.reference', self::REFERENCE)
                ->where('subscriptions.0.provider', 'tebex')
                ->where('subscriptions.0.provider_status', 'Overdue')
                ->where('subscriptions.0.fail_count', 2)
                ->where('subscriptions.0.cancel_reason', 'Card declined')
                ->where('subscriptions.0.renewals', 1)
                ->has('payments', 2)
                ->where('payments.0.renewal', false)
                ->where('payments.1.renewal', true));
    }

    #[Test]
    public function a_delivery_that_leaves_a_date_out_keeps_the_one_the_shop_has_and_one_that_says_there_is_none_clears_it(): void
    {
        [$order, $payment] = $this->start();

        $send = function (array $changes, array $without, string $date) use ($order, $payment): void {
            $subject = $this->tebexRecurringFor($order, $payment, self::REFERENCE, $changes);

            foreach ($without as $key) {
                unset($subject[$key]);
            }

            $this->deliver('recurring-payment.status-changed', $subject, null, $date)->assertOk();
        };

        $send(['next_payment_at' => '2099-11-21T11:34:13.000000Z', 'cancelled_at' => '2099-12-01T10:00:00.000000Z'], [], '2026-11-01T10:00:00+00:00');
        $this->assertSame('2099-11-21', $this->subscription()->next_payment_at->format('Y-m-d'));
        $this->assertSame('2099-12-01', $this->subscription()->cancelled_at->format('Y-m-d'));

        $send([], ['next_payment_at', 'cancelled_at'], '2026-11-02T10:00:00+00:00');
        $this->assertSame('2099-11-21', $this->subscription()->next_payment_at->format('Y-m-d'), 'not said, so not changed');
        $this->assertSame('2099-12-01', $this->subscription()->cancelled_at->format('Y-m-d'), 'not said, so not changed');

        $send(['next_payment_at' => null, 'cancelled_at' => null], [], '2026-11-03T10:00:00+00:00');
        $this->assertNull($this->subscription()->next_payment_at);
        $this->assertNull($this->subscription()->cancelled_at);
    }

    #[Test]
    public function a_renewal_that_was_held_and_comes_again_when_it_can_be_taken_is_recorded_as_a_renewal_not_as_the_orders_payment(): void
    {
        [$order, $payment] = $this->start();

        $this->deliver('payment.completed', $this->renewal($order, $payment, self::RENEWAL, ['price_paid' => ['amount' => 4.5, 'currency' => 'GBP']]))->assertOk();
        $this->assertSame(PaymentStatus::Review, Payment::query()->where('provider_reference', self::RENEWAL)->sole()->status);

        $this->deliver('payment.completed', $this->renewal($order, $payment))->assertOk();

        $renewal = Payment::query()->where('provider_reference', self::RENEWAL)->sole();
        $this->assertSame(PaymentStatus::Succeeded, $renewal->status);
        $this->assertTrue($renewal->raw['renewal']);
        $this->assertSame(2, Payment::query()->count());
        $this->assertSame(1, $this->subscription()->renewals_count);
        $this->assertSame(1, $order->events()->where('type', OrderEvent::PAID)->count(), 'the order was paid by its own payment, once');
    }

    #[Test]
    public function nothing_is_shown_as_coming_once_a_recurring_payment_is_over_even_if_a_date_was_recorded(): void
    {
        [$order] = $this->start();
        $this->subscription()->forceFill(['status' => SubscriptionStatus::Expired, 'next_payment_at' => now()->addDay()])->save();

        $this->get(URL::signedRoute('shop.checkout.complete', ['order' => $order->public_id]))->assertInertia(fn (Assert $page) => $page
            ->where('subscriptions.0.state', 'ended')
            ->where('subscriptions.0.state_label', 'Expired')
            ->where('subscriptions.0.next_payment_at', null));
    }

    // --- A renewal the shop never recorded, given back -----------------------------------------------------------------

    #[Test]
    public function a_refund_of_a_renewal_the_shop_never_recorded_is_not_taken_for_the_orders_own_payment(): void
    {
        [$order, $payment] = $this->start();

        // However the delivery points at the order (its basket's custom data, the recurring payment it belongs to, or
        // both), it is about a transaction the shop has no payment for.
        $shapes = [
            'both' => fn (array $subject) => $subject,
            'only the recurring payment' => function (array $subject) {
                $subject['products'][0]['custom'] = null;

                return $subject;
            },
            'only the basket' => function (array $subject) {
                unset($subject['recurring_payment_reference']);

                return $subject;
            },
        ];

        foreach ($shapes as $shape) {
            $this->deliver('payment.refunded', $shape($this->renewal($order, $payment, 'tbx-never-recorded', ['status' => ['id' => 2, 'description' => 'Refund']])))->assertOk();
        }

        $order->refresh();
        $this->assertSame(0, Refund::query()->count());
        $this->assertSame(OrderPaymentStatus::Paid, $order->payment_status);
        $this->assertSame('0.00', $order->refunded_total);
        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()->status);

        $calls = BillingWebhookCall::query()->where('type', 'payment.refunded')->get();
        $this->assertCount(3, $calls);
        foreach ($calls as $call) {
            $this->assertStringContainsString('has no record of', (string) $call->error);
        }
    }

    #[Test]
    public function a_dispute_over_a_renewal_the_shop_never_recorded_is_not_put_on_the_orders_own_payment(): void
    {
        [$order, $payment] = $this->start();

        foreach (['payment.dispute.opened', 'payment.dispute.lost'] as $type) {
            $this->deliver($type, $this->renewal($order, $payment, 'tbx-never-recorded', ['status' => ['id' => 3, 'description' => 'Chargeback']]))->assertOk();
        }

        $this->assertSame(0, Refund::query()->count());
        $this->assertSame(OrderPaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertSame([], $this->notes($order), 'a dispute over another payment is not a note on this one');
        $this->assertSame(2, BillingWebhookCall::query()->where('error', 'like', '%has no record of%')->count());
    }

    // --- Renewals that are held, counted and ordered ---------------------------------------------------------------------

    #[Test]
    public function a_renewal_held_for_its_currency_shows_the_currency_tebex_took_it_in(): void
    {
        [$order, $payment] = $this->start();
        $noAmount = $this->renewal($order, $payment, 'tbx-renewal-none');
        unset($noAmount['price_paid'], $noAmount['price']);

        $this->deliver('payment.completed', $this->renewal($order, $payment, 'tbx-renewal-gbp', ['price_paid' => ['amount' => 4.5, 'currency' => 'GBP']]))->assertOk();
        $this->deliver('payment.completed', $noAmount)->assertOk();

        $pounds = Payment::query()->where('provider_reference', 'tbx-renewal-gbp')->sole();
        $this->assertSame(PaymentStatus::Review, $pounds->status);
        $this->assertSame('4.50', $pounds->amount);
        $this->assertSame('GBP', $pounds->currency, 'staff look at it to decide what to do: it must say what was taken');

        $none = Payment::query()->where('provider_reference', 'tbx-renewal-none')->sole();
        $this->assertSame(PaymentStatus::Review, $none->status);
        $this->assertSame('0.00', $none->amount);
        $this->assertSame('USD', $none->currency, 'with no amount reported there is no other currency to show');
    }

    #[Test]
    public function a_renewal_is_counted_in_the_database_so_two_processed_at_once_are_both_counted(): void
    {
        [$order, $payment] = $this->start();

        // Another request counts a renewal just after this one has read the recurring payment (the receiver only locks
        // the delivery's own row, so nothing makes the two wait for each other).
        $counted = false;
        ProviderSubscription::retrieved(function (ProviderSubscription $subscription) use (&$counted): void {
            if (! $counted) {
                $counted = true;
                DB::table('provider_subscriptions')->where('id', $subscription->id)->increment('renewals_count');
            }
        });

        $this->deliver('payment.completed', $this->renewal($order, $payment))->assertOk();

        $this->assertTrue($counted, 'the other request had its turn');
        $this->assertSame(2, $this->subscription()->renewals_count);
    }

    #[Test]
    public function the_last_payment_is_the_newest_renewal_whatever_order_they_arrive_in(): void
    {
        [$order, $payment] = $this->start();

        $this->deliver('payment.completed', $this->renewal($order, $payment, 'tbx-renewal-late', ['settled_at' => '2026-12-05T10:00:00.000000Z']))->assertOk();
        $this->deliver('payment.completed', $this->renewal($order, $payment, 'tbx-renewal-early', ['settled_at' => '2026-11-05T10:00:00.000000Z']))->assertOk();

        $this->assertSame(2, $this->subscription()->renewals_count);
        $this->assertSame('2026-12-05', $this->subscription()->last_payment_at->format('Y-m-d'), 'the older one, arriving late, does not move it back');
    }
}
