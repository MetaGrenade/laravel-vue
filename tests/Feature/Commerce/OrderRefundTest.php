<?php

namespace Tests\Feature\Commerce;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\RefundStatus;
use App\Models\BillingWebhookCall;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Notifications\RefundIssued;
use App\Payments\Exceptions\RefundRejected;
use App\Support\Commerce\InventoryReserver;
use App\Support\Commerce\Money;
use App\Support\Commerce\OrderLifecycle;
use App\Support\Commerce\OrderRefunder;
use App\Support\Commerce\RefundException;
use App\Support\Commerce\RefundRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\InteractsWithCommerce;
use Tests\TestCase;

class OrderRefundTest extends TestCase
{
    use InteractsWithCommerce;
    use RefreshDatabase;

    private OrderRefunder $refunder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerce();
        Notification::fake();

        $this->refunder = $this->app->make(OrderRefunder::class);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function refund(Order $order, string $amount, array $options = []): Refund
    {
        return $this->refunder->request(
            $order,
            Money::parse($amount, $order->currency),
            new RefundRequest(...array_replace(['token' => (string) Str::uuid()], $options)),
        );
    }

    #[Test]
    public function a_full_refund_goes_through_stripe_and_closes_an_unshipped_order(): void
    {
        [$order, $payment] = $this->placePaidOrder(price: '25.00', quantity: 2, stock: 5);

        $refund = $this->refund($order, '50.00', ['reason' => 'requested_by_customer', 'note' => 'Changed their mind']);

        // Stripe was asked for exactly this payment and amount, tagged so it can be recognised later.
        $sent = array_values($this->stripe->refunds)[0];
        $this->assertSame($payment->fresh()->provider_payment_id, $sent['payment_intent']);
        $this->assertSame(5000, $sent['amount']);
        $this->assertSame('requested_by_customer', $sent['reason']);
        $this->assertSame((string) $refund->id, $sent['metadata']['refund_id']);

        $this->assertSame(RefundStatus::Succeeded, $refund->status);
        $this->assertSame('re_test_1', $refund->provider_reference);
        $this->assertSame('stripe', $refund->provider);
        $this->assertNotNull($refund->processed_at);

        $order->refresh();
        $this->assertSame(OrderPaymentStatus::Refunded, $order->payment_status);
        $this->assertSame('50.00', $order->refunded_total);
        $this->assertSame(OrderStatus::Cancelled, $order->status, 'nothing left to ship');
        $this->assertNotNull($order->cancelled_at);
        $this->assertTrue($order->isPaid(), 'it was paid for once');

        $this->assertSame(
            [OrderEvent::PAID, OrderEvent::REFUND_REQUESTED, OrderEvent::CANCELLED, OrderEvent::REFUND_SUCCEEDED],
            $order->events()->orderBy('id')->pluck('type')->all(),
        );
    }

    #[Test]
    public function a_partial_refund_keeps_the_order_open(): void
    {
        [$order] = $this->placePaidOrder(price: '25.00', quantity: 2);

        $refund = $this->refund($order, '10.00');

        $order->refresh();
        $this->assertSame(RefundStatus::Succeeded, $refund->status);
        $this->assertSame(OrderPaymentStatus::PartiallyRefunded, $order->payment_status);
        $this->assertSame('10.00', $order->refunded_total);
        $this->assertSame(OrderStatus::Processing, $order->status);
        $this->assertSame('40.00', $this->refunder->refundable($order)->toDecimal());
    }

    #[Test]
    public function several_partial_refunds_add_up_and_the_last_one_completes_the_order(): void
    {
        [$order] = $this->placePaidOrder(price: '20.00');

        $this->refund($order, '5.00');
        $this->refund($order, '5.00');
        $this->assertSame(OrderPaymentStatus::PartiallyRefunded, $order->fresh()->payment_status);
        $this->assertSame('10.00', $order->fresh()->refunded_total);

        $this->refund($order, '10.00');

        $this->assertSame(OrderPaymentStatus::Refunded, $order->fresh()->payment_status);
        $this->assertSame('20.00', $order->fresh()->refunded_total);
        $this->assertSame(3, Refund::count());
    }

    #[Test]
    public function a_refund_cannot_exceed_what_is_left(): void
    {
        [$order] = $this->placePaidOrder(price: '20.00');
        $this->refund($order, '15.00');

        try {
            $this->refund($order, '5.01');
            $this->fail('A refund above the balance was accepted.');
        } catch (RefundException $exception) {
            $this->assertSame('amount', $exception->field);
            $this->assertStringContainsString('At most 5.00 USD', $exception->getMessage());
        }

        $this->assertSame(1, Refund::count());
        $this->assertCount(1, $this->stripe->refunds, 'Stripe was not asked');
    }

    #[Test]
    public function nothing_can_be_refunded_once_everything_has_been(): void
    {
        [$order] = $this->placePaidOrder(price: '20.00');
        $this->refund($order, '20.00');

        $this->expectException(RefundException::class);
        $this->expectExceptionMessage('nothing left to refund');

        $this->refund($order, '1.00');
    }

    #[Test]
    public function an_amount_of_zero_or_in_another_currency_is_refused(): void
    {
        [$order] = $this->placePaidOrder(price: '20.00');

        foreach (['0.00', '0'] as $amount) {
            try {
                $this->refund($order, $amount);
                $this->fail('A zero refund was accepted.');
            } catch (RefundException $exception) {
                $this->assertStringContainsString('greater than zero', $exception->getMessage());
            }
        }

        $this->expectException(RefundException::class);
        $this->expectExceptionMessage('must be too');

        $this->refunder->request($order, Money::parse('5.00', 'EUR'), new RefundRequest(token: (string) Str::uuid()));
    }

    #[Test]
    public function an_unpaid_order_cannot_be_refunded(): void
    {
        [$order] = $this->placeOrder();

        $this->expectException(RefundException::class);
        $this->expectExceptionMessage('Only an order that has been paid');

        $this->refund($order, '5.00');
    }

    #[Test]
    public function submitting_the_same_refund_twice_refunds_once(): void
    {
        [$order] = $this->placePaidOrder(price: '20.00');
        $token = (string) Str::uuid();

        $first = $this->refund($order, '5.00', ['token' => $token]);
        $second = $this->refund($order, '5.00', ['token' => $token]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Refund::count());
        $this->assertCount(1, $this->stripe->refunds);
        $this->assertSame('5.00', $order->fresh()->refunded_total);
    }

    #[Test]
    public function a_token_cannot_be_reused_for_another_order(): void
    {
        [$order] = $this->placePaidOrder(price: '20.00');
        $token = (string) Str::uuid();
        $this->refund($order, '5.00', ['token' => $token]);

        [$other] = $this->placePaidOrder(price: '20.00');

        $this->expectException(RefundException::class);
        $this->expectExceptionMessage('already been used');

        $this->refund($other, '5.00', ['token' => $token]);
    }

    #[Test]
    public function stripe_refusing_a_refund_records_it_as_failed_and_frees_the_balance(): void
    {
        [$order] = $this->placePaidOrder(price: '20.00');
        $this->stripe->failRefundWith = new RefundRejected('Charge ch_1 has already been refunded.');

        $refund = $this->refund($order, '20.00');

        $this->assertSame(RefundStatus::Failed, $refund->status);
        $this->assertSame('Charge ch_1 has already been refunded.', $refund->failure_reason);
        $this->assertNull($refund->processed_at);

        $order->refresh();
        $this->assertSame(OrderPaymentStatus::Paid, $order->payment_status);
        $this->assertSame('0.00', $order->refunded_total);
        $this->assertSame('20.00', $this->refunder->refundable($order)->toDecimal(), 'the balance is free again');
        $this->assertContains(OrderEvent::REFUND_FAILED, $order->events()->pluck('type')->all());
        Notification::assertSentOnDemandTimes(RefundIssued::class, 0);
    }

    #[Test]
    public function an_unknown_outcome_leaves_the_refund_pending_and_holds_the_balance(): void
    {
        [$order] = $this->placePaidOrder(price: '20.00');
        $this->stripe->failRefundWith = new RuntimeException('Connection timed out');

        $refund = $this->refund($order, '20.00');

        // It may exist at Stripe, so it is not called failed and the money is not offered again.
        $this->assertSame(RefundStatus::Pending, $refund->status);
        $this->assertNull($refund->provider_reference);
        $this->assertSame('0.00', $this->refunder->refundable($order->fresh())->toDecimal());
        $this->assertSame(OrderPaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertContains(OrderEvent::REFUND_UNCONFIRMED, $order->events()->pluck('type')->all());
    }

    #[Test]
    public function a_refund_whose_reply_was_lost_is_found_again_by_checking(): void
    {
        [$order] = $this->placePaidOrder(price: '20.00');
        $this->stripe->loseRefundReply = true;

        $refund = $this->refund($order, '20.00');

        // Stripe made the refund; we never heard.
        $this->assertSame(RefundStatus::Pending, $refund->status);
        $this->assertCount(1, $this->stripe->refunds);

        $refund = $this->refunder->check($refund);

        $this->assertSame(RefundStatus::Succeeded, $refund->status);
        $this->assertSame('re_test_1', $refund->provider_reference);
        $this->assertSame(1, Refund::count(), 'the existing record was matched, not duplicated');
        $this->assertSame(OrderPaymentStatus::Refunded, $order->fresh()->payment_status);
    }

    #[Test]
    public function retrying_a_request_that_never_got_an_answer_cannot_refund_twice(): void
    {
        [$order] = $this->placePaidOrder(price: '20.00');
        $token = (string) Str::uuid();
        $this->stripe->loseRefundReply = true;
        $this->refund($order, '20.00', ['token' => $token]);

        $this->stripe->loseRefundReply = false;
        $retried = $this->refund($order, '20.00', ['token' => $token]);

        // Same idempotency key: Stripe returns the refund it already made.
        $this->assertCount(1, $this->stripe->refunds);
        $this->assertSame(RefundStatus::Succeeded, $retried->status);
        $this->assertSame(1, Refund::count());
    }

    #[Test]
    public function a_refund_stripe_never_received_is_given_up_on_after_a_while(): void
    {
        [$order] = $this->placePaidOrder(price: '20.00');
        $this->stripe->failRefundWith = new RuntimeException('Connection timed out');
        $refund = $this->refund($order, '20.00');

        $this->travel(5)->minutes();
        $this->assertSame(RefundStatus::Pending, $this->refunder->check($refund)->status, 'still might be in flight');

        $this->travel(6)->minutes();
        $refund = $this->refunder->check($refund);

        $this->assertSame(RefundStatus::Failed, $refund->status);
        $this->assertStringContainsString('never reached', (string) $refund->failure_reason);
        $this->assertSame('20.00', $this->refunder->refundable($order->fresh())->toDecimal());
    }

    #[Test]
    public function a_pending_refund_holds_the_balance_until_stripe_settles_it(): void
    {
        [$order] = $this->placePaidOrder(price: '20.00');
        $this->stripe->nextRefundStatus = 'pending';

        $refund = $this->refund($order, '20.00');

        $this->assertSame(RefundStatus::Pending, $refund->status);
        $this->assertSame('re_test_1', $refund->provider_reference);
        $this->assertSame(OrderPaymentStatus::Paid, $order->fresh()->payment_status, 'not refunded until it succeeds');

        $this->stripe->setRefundStatus('re_test_1', 'succeeded');
        $this->deliverStripeEvent($this->stripeEvent('refund.updated', $this->stripe->refunds['re_test_1']))->assertOk();

        $this->assertSame(RefundStatus::Succeeded, $refund->fresh()->status);
        $this->assertSame(OrderPaymentStatus::Refunded, $order->fresh()->payment_status);
    }

    #[Test]
    public function a_refund_stripe_later_fails_returns_the_order_to_paid(): void
    {
        [$order] = $this->placePaidOrder(price: '20.00');
        $refund = $this->refund($order, '5.00');
        $this->assertSame(OrderPaymentStatus::PartiallyRefunded, $order->fresh()->payment_status);

        $failed = $this->stripe->setRefundStatus('re_test_1', 'failed', 'expired_or_canceled_card');
        $this->deliverStripeEvent($this->stripeEvent('refund.failed', $failed))->assertOk();

        $refund->refresh();
        $order->refresh();
        $this->assertSame(RefundStatus::Failed, $refund->status);
        $this->assertSame('expired_or_canceled_card', $refund->failure_reason);
        $this->assertSame(OrderPaymentStatus::Paid, $order->payment_status);
        $this->assertSame('0.00', $order->refunded_total);
        $this->assertSame('20.00', $this->refunder->refundable($order)->toDecimal());
    }

    #[Test]
    public function a_refund_made_in_the_stripe_dashboard_is_picked_up(): void
    {
        [$order, $payment] = $this->placePaidOrder(price: '20.00');

        $external = $this->stripe->refundExternally($payment->fresh()->provider_payment_id, 700);
        $this->deliverStripeEvent($this->stripeEvent('refund.created', $external))->assertOk();

        $refund = Refund::sole();
        $this->assertSame('re_test_1', $refund->provider_reference);
        $this->assertSame('7.00', $refund->amount);
        $this->assertSame(RefundStatus::Succeeded, $refund->status);
        $this->assertNull($refund->user_id, 'not issued from the shop');
        $this->assertFalse($refund->notify_customer);
        $this->assertSame('requested_by_customer', $refund->reason);

        $order->refresh();
        $this->assertSame(OrderPaymentStatus::PartiallyRefunded, $order->payment_status);
        $this->assertSame('7.00', $order->refunded_total);
        Notification::assertSentOnDemandTimes(RefundIssued::class, 0);
    }

    #[Test]
    public function a_charge_refunded_event_is_enough_to_find_every_refund(): void
    {
        [$order, $payment] = $this->placePaidOrder(price: '20.00');
        $intent = $payment->fresh()->provider_payment_id;
        $this->stripe->refundExternally($intent, 500);
        $this->stripe->refundExternally($intent, 300);

        // charge.refunded carries a charge, not a refund; the payment intent is all we read from it.
        $this->deliverStripeEvent($this->stripeEvent('charge.refunded', [
            'id' => 'ch_test_1',
            'object' => 'charge',
            'payment_intent' => $intent,
        ]))->assertOk();

        $this->assertSame(2, Refund::count());
        $this->assertSame('8.00', $order->fresh()->refunded_total);
    }

    #[Test]
    public function a_redelivered_refund_event_changes_nothing(): void
    {
        [$order, $payment] = $this->placePaidOrder(price: '20.00');
        $event = $this->stripeEvent('refund.created', $this->stripe->refundExternally($payment->fresh()->provider_payment_id, 400));

        $this->deliverStripeEvent($event)->assertOk();
        $this->deliverStripeEvent($event)->assertOk()->assertSee('already handled');

        $this->assertSame(1, Refund::count());
        $this->assertSame('4.00', $order->fresh()->refunded_total);
        $this->assertSame(1, BillingWebhookCall::where('type', 'refund.created')->count());
    }

    #[Test]
    public function a_refund_event_for_a_payment_this_shop_did_not_take_is_ignored(): void
    {
        $this->placePaidOrder();

        $this->deliverStripeEvent($this->stripeEvent('refund.created', [
            'id' => 're_other',
            'object' => 'refund',
            'payment_intent' => 'pi_from_a_subscription',
            'amount' => 1000,
            'currency' => 'usd',
            'status' => 'succeeded',
        ]))->assertOk()->assertSee('Not a refund of a shop payment');

        $this->assertSame(0, Refund::count());
        $this->assertSame(0, BillingWebhookCall::where('type', 'refund.created')->count());
    }

    #[Test]
    public function a_refund_event_with_a_bad_signature_is_rejected(): void
    {
        [, $payment] = $this->placePaidOrder();
        $event = $this->stripeEvent('refund.created', $this->stripe->refundExternally($payment->fresh()->provider_payment_id, 400));

        $this->deliverStripeEvent($event, 'whsec_wrong')->assertStatus(400);

        $this->assertSame(0, Refund::count());
    }

    #[Test]
    public function stripe_being_unreachable_makes_the_webhook_retry(): void
    {
        [, $payment] = $this->placePaidOrder();
        $event = $this->stripeEvent('refund.created', $this->stripe->refundExternally($payment->fresh()->provider_payment_id, 400));
        $this->stripe->failListRefundsWith = new RuntimeException('Stripe is down');

        $this->deliverStripeEvent($event)->assertStatus(500);
        $this->assertSame(0, Refund::count());

        // Stripe redelivers; this time it works.
        $this->stripe->failListRefundsWith = null;
        $this->deliverStripeEvent($event)->assertOk();
        $this->assertSame(1, Refund::count());
    }

    #[Test]
    public function the_customer_is_emailed_when_a_refund_succeeds_if_asked(): void
    {
        [$order] = $this->placePaidOrder(price: '20.00');

        $this->refund($order, '5.00', ['notifyCustomer' => true]);

        Notification::assertSentOnDemandTimes(RefundIssued::class, 1);
        Notification::assertSentOnDemand(RefundIssued::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'buyer@example.com');
    }

    #[Test]
    public function the_customer_is_not_emailed_when_staff_choose_not_to(): void
    {
        [$order] = $this->placePaidOrder(price: '20.00');

        $this->refund($order, '5.00', ['notifyCustomer' => false]);

        Notification::assertSentOnDemandTimes(RefundIssued::class, 0);
    }

    #[Test]
    public function the_email_is_sent_once_even_when_stripe_repeats_itself(): void
    {
        [$order] = $this->placePaidOrder(price: '20.00');
        $this->refund($order, '5.00');

        $this->deliverStripeEvent($this->stripeEvent('refund.updated', $this->stripe->refunds['re_test_1']))->assertOk();
        $this->deliverStripeEvent($this->stripeEvent('refund.updated', $this->stripe->refunds['re_test_1']))->assertOk();

        Notification::assertSentOnDemandTimes(RefundIssued::class, 1);
    }

    #[Test]
    public function a_full_refund_can_put_the_stock_back(): void
    {
        [$order, , $product] = $this->placePaidOrder(price: '10.00', quantity: 3, stock: 10);
        $this->assertSame(7, InventoryItem::sole()->quantity);

        $this->refund($order, '30.00', ['restock' => true]);

        $this->assertSame(10, InventoryItem::sole()->quantity);
        $this->assertSame(1, InventoryMovement::where('reason', InventoryMovement::RESTOCK)->count());
        $this->assertSame($product->id, InventoryItem::sole()->product_id);
    }

    #[Test]
    public function a_full_refund_leaves_the_stock_alone_unless_asked(): void
    {
        [$order] = $this->placePaidOrder(price: '10.00', quantity: 3, stock: 10);

        $this->refund($order, '30.00');

        $this->assertSame(7, InventoryItem::sole()->quantity);
        $this->assertSame(0, InventoryMovement::where('reason', InventoryMovement::RESTOCK)->count());
    }

    #[Test]
    public function stock_only_goes_back_when_the_refund_completes_the_order(): void
    {
        [$order] = $this->placePaidOrder(price: '10.00', quantity: 3, stock: 10);

        try {
            $this->refund($order, '10.00', ['restock' => true]);
            $this->fail('Restocking was allowed for a partial refund.');
        } catch (RefundException $exception) {
            $this->assertSame('restock', $exception->field);
        }

        $this->assertSame(0, Refund::count());
        $this->assertSame(7, InventoryItem::sole()->quantity);

        // After an earlier partial refund, refunding the rest completes it.
        $this->refund($order, '10.00');
        $this->refund($order, '20.00', ['restock' => true]);

        $this->assertSame(10, InventoryItem::sole()->quantity);
    }

    #[Test]
    public function stock_is_never_returned_twice(): void
    {
        [$order] = $this->placePaidOrder(price: '10.00', quantity: 3, stock: 10);
        $this->refund($order, '30.00', ['restock' => true]);

        $inventory = $this->app->make(InventoryReserver::class);
        $inventory->restock($order->fresh(), 'again');
        $inventory->release($order->fresh(), 'and again');

        $this->assertSame(10, InventoryItem::sole()->quantity);
    }

    #[Test]
    public function a_refund_recorded_by_hand_asks_no_one(): void
    {
        [$order] = $this->placePaidOrder(price: '20.00');
        $staff = User::factory()->create();

        $refund = $this->refunder->request(
            $order,
            Money::parse('20.00', 'USD'),
            new RefundRequest(token: (string) Str::uuid(), note: 'Refunded in cash at the counter', manual: true),
            $staff,
        );

        $this->assertSame([], $this->stripe->refunds, 'Stripe was not asked');
        $this->assertSame(RefundStatus::Succeeded, $refund->status);
        $this->assertNull($refund->provider);
        $this->assertNull($refund->provider_reference);
        $this->assertFalse($refund->isProviderRefund());
        $this->assertSame($staff->id, $refund->user_id);
        $this->assertSame(OrderPaymentStatus::Refunded, $order->fresh()->payment_status);

        // One line in the history, not a request followed by a result.
        $events = $order->events()->whereIn('type', [OrderEvent::REFUND_REQUESTED, OrderEvent::REFUND_SUCCEEDED])->get();
        $this->assertSame([OrderEvent::REFUND_SUCCEEDED], $events->pluck('type')->all());
        $this->assertSame('Refunded 20.00 USD (recorded by hand)', $events->first()->message);
        $this->assertSame($staff->id, $events->first()->user_id);
    }

    #[Test]
    public function an_order_that_cannot_be_refunded_through_its_provider_can_only_be_recorded(): void
    {
        [$order] = $this->placePaidOrder(price: '20.00');
        Payment::query()->where('order_id', $order->id)->update(['provider_payment_id' => null]);

        $this->expectException(RefundException::class);
        $this->expectExceptionMessage('cannot refund it from here');

        $this->refund($order, '5.00');
    }

    #[Test]
    public function a_refunded_order_cannot_be_paid_or_cancelled_again(): void
    {
        [$order, $payment] = $this->placePaidOrder(price: '20.00');
        $this->refund($order, '20.00');
        $lifecycle = $this->app->make(OrderLifecycle::class);

        $this->assertFalse($lifecycle->cancel($order->fresh()), 'it is not an unpaid order');
        $this->assertFalse($lifecycle->markPaid($order->fresh(), $payment), 'it was already paid for');

        $order->refresh();
        $this->assertSame(OrderPaymentStatus::Refunded, $order->payment_status);
        $this->assertSame(OrderStatus::Cancelled, $order->status);
    }

    #[Test]
    public function a_shipped_order_stays_completed_when_it_is_refunded(): void
    {
        [$order] = $this->placePaidOrder(price: '20.00');
        $this->assertTrue($this->app->make(OrderLifecycle::class)->fulfil($order->fresh()));

        $this->refund($order, '20.00');

        $order->refresh();
        $this->assertSame(OrderStatus::Completed, $order->status, 'it already went out');
        $this->assertSame(OrderPaymentStatus::Refunded, $order->payment_status);
    }

    #[Test]
    public function the_refund_amount_is_exact_for_awkward_prices(): void
    {
        [$order] = $this->placePaidOrder(price: '19.99', quantity: 3);

        $this->refund($order, '19.99');
        $this->refund($order, '39.98');

        $this->assertSame('59.97', $order->fresh()->refunded_total);
        $this->assertSame(OrderPaymentStatus::Refunded, $order->fresh()->payment_status);
        $this->assertSame([1999, 3998], collect($this->stripe->refunds)->pluck('amount')->all());
    }
}
