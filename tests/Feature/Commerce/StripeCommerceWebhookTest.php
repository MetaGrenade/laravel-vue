<?php

namespace Tests\Feature\Commerce;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\BillingWebhookCall;
use App\Models\Cart;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\SystemSetting;
use App\Notifications\OrderConfirmation;
use App\Support\Commerce\Digital\DigitalFulfilment;
use App\Support\Commerce\InventoryReserver;
use App\Support\Commerce\OrderLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\InteractsWithCommerce;
use Tests\TestCase;

class StripeCommerceWebhookTest extends TestCase
{
    use InteractsWithCommerce;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerce();
        Notification::fake();
    }

    #[Test]
    public function a_paid_checkout_session_marks_the_order_paid(): void
    {
        [$order, $payment] = $this->placeOrder(price: '25.00', quantity: 2, stock: 5);

        $session = $this->sessionFor($payment);
        $this->deliverStripeEvent($this->stripeEvent('checkout.session.completed', $session))->assertOk();

        $order->refresh();
        $payment->refresh();

        $this->assertSame(OrderStatus::Processing, $order->status);
        $this->assertSame(OrderPaymentStatus::Paid, $order->payment_status);
        $this->assertNotNull($order->paid_at);
        $this->assertNull($order->expires_at, 'a paid order no longer expires');
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame('pi_test_'.$payment->provider_reference, $payment->provider_payment_id);
        $this->assertSame('converted', Cart::find($order->cart_id)->status);

        // The stock stays taken.
        $this->assertSame(3, InventoryItem::sole()->quantity);

        Notification::assertSentOnDemand(OrderConfirmation::class, function ($notification, $channels, $notifiable) {
            return $notifiable->routes['mail'] === 'buyer@example.com';
        });
    }

    #[Test]
    public function the_event_is_recorded_once_with_its_outcome(): void
    {
        [, $payment] = $this->placeOrder();
        $event = $this->stripeEvent('checkout.session.completed', $this->sessionFor($payment));

        $this->deliverStripeEvent($event)->assertOk();

        $call = BillingWebhookCall::sole();
        $this->assertSame('stripe', $call->provider);
        $this->assertSame($event['id'], $call->external_id);
        $this->assertSame($event['id'], $call->stripe_id);
        $this->assertSame('checkout.session.completed', $call->type);
        $this->assertNotNull($call->processed_at);
        $this->assertSame(1, $call->attempts);
        $this->assertNull($call->error);
    }

    #[Test]
    public function a_redelivered_event_changes_nothing(): void
    {
        [$order, $payment] = $this->placeOrder();
        $event = $this->stripeEvent('checkout.session.completed', $this->sessionFor($payment));

        $this->deliverStripeEvent($event)->assertOk();
        $paidAt = $order->fresh()->paid_at;

        $this->travel(5)->minutes();
        $this->deliverStripeEvent($event)->assertOk()->assertSee('already handled');

        $this->assertEquals($paidAt, $order->fresh()->paid_at);
        $this->assertSame(1, BillingWebhookCall::count());
        $this->assertSame(1, BillingWebhookCall::sole()->attempts);
        Notification::assertSentOnDemandTimes(OrderConfirmation::class, 1);
    }

    #[Test]
    public function two_different_events_for_one_payment_still_pay_the_order_once(): void
    {
        [$order, $payment] = $this->placeOrder();
        $session = $this->sessionFor($payment);

        $this->deliverStripeEvent($this->stripeEvent('checkout.session.completed', $session))->assertOk();
        $this->deliverStripeEvent($this->stripeEvent('checkout.session.async_payment_succeeded', $session))->assertOk();

        $this->assertSame(OrderStatus::Processing, $order->fresh()->status);
        $this->assertSame(2, BillingWebhookCall::count());
        Notification::assertSentOnDemandTimes(OrderConfirmation::class, 1);
    }

    #[Test]
    public function a_bad_signature_is_rejected_and_changes_nothing(): void
    {
        [$order, $payment] = $this->placeOrder();
        $event = $this->stripeEvent('checkout.session.completed', $this->sessionFor($payment));

        $this->deliverStripeEvent($event, secret: 'whsec_wrong')->assertStatus(400);
        $this->postJson(route('stripe.webhook'), $event)->assertStatus(400);

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertSame(0, BillingWebhookCall::count());
    }

    #[Test]
    public function a_stale_signature_is_rejected(): void
    {
        [$order, $payment] = $this->placeOrder();
        $event = $this->stripeEvent('checkout.session.completed', $this->sessionFor($payment));
        $body = json_encode($event);
        $old = time() - 3600;
        $signature = hash_hmac('sha256', $old.'.'.$body, $this->webhookSecret);

        $this->call('POST', route('stripe.webhook'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$old},v1={$signature}",
        ], $body)->assertStatus(400);

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    #[Test]
    public function sessions_that_are_not_for_shop_orders_are_ignored(): void
    {
        [$order, $payment] = $this->placeOrder();
        $session = $this->sessionFor($payment);
        // A plan subscription checkout carries no commerce marker.
        $session['metadata'] = [];

        $this->deliverStripeEvent($this->stripeEvent('checkout.session.completed', $session))->assertOk();

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertSame(0, BillingWebhookCall::count());
    }

    #[Test]
    public function a_session_this_shop_has_no_payment_for_is_ignored(): void
    {
        Log::spy();
        [$order, $payment] = $this->placeOrder();
        $session = $this->sessionFor($payment);
        $session['id'] = 'cs_test_somebody_elses';

        $this->deliverStripeEvent($this->stripeEvent('checkout.session.completed', $session))->assertOk();

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    #[Test]
    public function a_session_that_names_a_different_order_is_not_applied(): void
    {
        [$order, $payment] = $this->placeOrder();
        $session = $this->sessionFor($payment);
        $session['client_reference_id'] = '01hzzzzzzzzzzzzzzzzzzzzzzz';

        $this->deliverStripeEvent($this->stripeEvent('checkout.session.completed', $session))->assertOk();

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    #[Test]
    public function a_payment_for_the_wrong_amount_is_flagged_not_applied(): void
    {
        Log::spy();
        [$order, $payment] = $this->placeOrder(price: '25.00');
        $session = $this->sessionFor($payment);
        $session['amount_total'] = 100;

        $this->deliverStripeEvent($this->stripeEvent('checkout.session.completed', $session))->assertOk();

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertSame(OrderPaymentStatus::Unpaid, $order->fresh()->payment_status);
        $this->assertSame(PaymentStatus::Review, $payment->fresh()->status);
        Notification::assertNothingSent();
        Log::shouldHaveReceived('critical')->once();
    }

    #[Test]
    public function a_payment_in_the_wrong_currency_is_flagged_not_applied(): void
    {
        Log::spy();
        [$order, $payment] = $this->placeOrder(price: '25.00');
        $session = $this->sessionFor($payment);
        $session['currency'] = 'eur';

        $this->deliverStripeEvent($this->stripeEvent('checkout.session.completed', $session))->assertOk();

        $this->assertSame(PaymentStatus::Review, $payment->fresh()->status);
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    #[Test]
    public function an_unpaid_completed_session_waits_for_the_asynchronous_result(): void
    {
        [$order, $payment] = $this->placeOrder();
        $session = $this->sessionFor($payment, paid: false);
        $session['status'] = 'complete';
        $session['payment_intent'] = 'pi_async';

        $this->deliverStripeEvent($this->stripeEvent('checkout.session.completed', $session))->assertOk();

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertSame('pi_async', $payment->fresh()->provider_payment_id);

        // The bank confirms later.
        $paid = $this->sessionFor($payment);
        $this->deliverStripeEvent($this->stripeEvent('checkout.session.async_payment_succeeded', $paid))->assertOk();

        $this->assertSame(OrderPaymentStatus::Paid, $order->fresh()->payment_status);
    }

    #[Test]
    public function a_failed_asynchronous_payment_cancels_the_order_and_frees_the_stock(): void
    {
        [$order, $payment] = $this->placeOrder(quantity: 2, stock: 5);
        $this->assertSame(3, InventoryItem::sole()->quantity);

        $this->deliverStripeEvent($this->stripeEvent('checkout.session.async_payment_failed', $this->sessionFor($payment, paid: false)))->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(OrderPaymentStatus::Failed, $order->payment_status);
        $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);
        $this->assertSame(5, InventoryItem::sole()->quantity);
        Notification::assertNothingSent();
    }

    #[Test]
    public function an_expired_session_cancels_the_order_and_frees_the_stock(): void
    {
        [$order, $payment] = $this->placeOrder(quantity: 2, stock: 5);

        $this->deliverStripeEvent($this->stripeEvent('checkout.session.expired', $this->sessionFor($payment, paid: false)))->assertOk();

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(PaymentStatus::Canceled, $payment->fresh()->status);
        $this->assertSame(5, InventoryItem::sole()->quantity);
    }

    #[Test]
    public function a_payment_that_arrives_after_cancellation_still_honours_the_order(): void
    {
        Log::spy();
        [$order, $payment] = $this->placeOrder(quantity: 2, stock: 5);
        $session = $this->sessionFor($payment, paid: false);

        $this->deliverStripeEvent($this->stripeEvent('checkout.session.expired', $session))->assertOk();
        $this->assertSame(5, InventoryItem::sole()->quantity);

        // The customer's payment went through just as the order was being cancelled.
        $this->deliverStripeEvent($this->stripeEvent('checkout.session.completed', $this->sessionFor($payment)))->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::Processing, $order->status);
        $this->assertSame(OrderPaymentStatus::Paid, $order->payment_status);
        $this->assertNull($order->cancelled_at);
        $this->assertTrue($order->metadata['late_payment']);
        $this->assertSame(3, InventoryItem::sole()->quantity, 'the stock is taken again');
        $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()->status);
        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    #[Test]
    public function a_failure_while_processing_asks_stripe_to_retry_and_a_retry_succeeds(): void
    {
        [$order, $payment] = $this->placeOrder();
        $event = $this->stripeEvent('checkout.session.completed', $this->sessionFor($payment));

        // The routed controller is built once and reused, so the failure is switched
        // through shared state rather than by swapping the binding.
        $state = new \stdClass;
        $state->fail = true;

        $this->app->bind(OrderLifecycle::class, fn ($app) => new class($app->make(InventoryReserver::class), $app->make(DigitalFulfilment::class), $state) extends OrderLifecycle
        {
            public function __construct(InventoryReserver $inventory, DigitalFulfilment $digital, private readonly \stdClass $state)
            {
                parent::__construct($inventory, $digital);
            }

            public function markPaid(Order $order, Payment $payment, array $paymentAttributes = []): bool
            {
                if ($this->state->fail) {
                    throw new RuntimeException('database went away');
                }

                return parent::markPaid($order, $payment, $paymentAttributes);
            }
        });

        $this->deliverStripeEvent($event)->assertStatus(500);

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status, 'a failed attempt leaves nothing half-applied');
        $call = BillingWebhookCall::sole();
        $this->assertNull($call->processed_at);
        $this->assertSame(1, $call->attempts);
        $this->assertSame('database went away', $call->error);

        // Stripe redelivers the same event; this time it works.
        $state->fail = false;

        $this->deliverStripeEvent($event)->assertOk();

        $this->assertSame(OrderStatus::Processing, $order->fresh()->status);
        $call->refresh();
        $this->assertNotNull($call->processed_at);
        $this->assertSame(2, $call->attempts);
        $this->assertNull($call->error);
        $this->assertSame(1, BillingWebhookCall::count());
    }

    #[Test]
    public function the_webhook_still_settles_orders_when_the_shop_is_switched_off(): void
    {
        [$order, $payment] = $this->placeOrder();
        SystemSetting::set('website_sections', ['blog' => true, 'forum' => true, 'support' => true, 'commerce' => false]);

        $this->deliverStripeEvent($this->stripeEvent('checkout.session.completed', $this->sessionFor($payment)))->assertOk();

        $this->assertSame(OrderPaymentStatus::Paid, $order->fresh()->payment_status);
    }
}
