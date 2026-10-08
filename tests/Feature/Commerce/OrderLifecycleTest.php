<?php

namespace Tests\Feature\Commerce;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\OrderCancelled;
use App\Events\OrderPaid;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Support\Commerce\OrderLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithCommerce;
use Tests\TestCase;

class OrderLifecycleTest extends TestCase
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
    public function cancelling_releases_the_stock_exactly_once(): void
    {
        [$order] = $this->placeOrder(quantity: 3, stock: 10);
        $lifecycle = app(OrderLifecycle::class);

        $this->assertSame(7, InventoryItem::sole()->quantity);

        $this->assertTrue($lifecycle->cancel($order, 'test'));
        $this->assertFalse($lifecycle->cancel($order, 'test'), 'the second cancel is a no-op');

        $this->assertSame(10, InventoryItem::sole()->quantity);
        $this->assertSame([-3, 3], InventoryMovement::orderBy('id')->pluck('delta')->all());
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertNotNull($order->fresh()->cancelled_at);
    }

    #[Test]
    public function the_ledger_always_explains_the_stock_level(): void
    {
        [$order, , $product] = $this->placeOrder(quantity: 4, stock: 10);
        $lifecycle = app(OrderLifecycle::class);

        $lifecycle->cancel($order, 'test');

        $net = InventoryMovement::query()->where('order_id', $order->id)->sum('delta');

        $this->assertSame(0, (int) $net, 'a cancelled order holds nothing');
        $this->assertSame(10, $product->inventoryItems()->sole()->quantity);
    }

    #[Test]
    public function a_paid_order_cannot_be_cancelled_here(): void
    {
        [$order, $payment] = $this->placeOrder(quantity: 2, stock: 10);
        $lifecycle = app(OrderLifecycle::class);
        $lifecycle->markPaid($order, $payment);

        $this->assertFalse($lifecycle->cancel($order, 'test'));

        $this->assertSame(OrderStatus::Processing, $order->fresh()->status);
        $this->assertSame(8, InventoryItem::sole()->quantity, 'paid stock stays taken');
    }

    #[Test]
    public function marking_paid_twice_applies_it_once(): void
    {
        Event::fake([OrderPaid::class]);
        [$order, $payment] = $this->placeOrder();
        $lifecycle = app(OrderLifecycle::class);

        $this->assertTrue($lifecycle->markPaid($order, $payment));
        $this->assertFalse($lifecycle->markPaid($order, $payment));

        Event::assertDispatchedTimes(OrderPaid::class, 1);
    }

    #[Test]
    public function a_second_successful_payment_is_recorded_but_never_applied_twice(): void
    {
        [$order, $payment] = $this->placeOrder();
        $lifecycle = app(OrderLifecycle::class);
        $lifecycle->markPaid($order, $payment);
        $paidAt = $order->fresh()->paid_at;

        $second = Payment::factory()->create([
            'order_id' => $order->id,
            'amount' => $order->grand_total,
            'provider_reference' => 'cs_test_second',
        ]);

        $this->assertFalse($lifecycle->markPaid($order, $second));

        $this->assertSame(PaymentStatus::Succeeded, $second->fresh()->status, 'the money was taken, so it is on record for a refund');
        $this->assertEquals($paidAt, $order->fresh()->paid_at);
    }

    #[Test]
    public function events_fire_after_the_state_change(): void
    {
        Event::fake([OrderPaid::class, OrderCancelled::class]);
        [$paid, $payment] = $this->placeOrder();
        [$cancelled] = $this->placeOrder();
        $lifecycle = app(OrderLifecycle::class);

        $lifecycle->markPaid($paid, $payment);
        $lifecycle->cancel($cancelled, 'expired');

        Event::assertDispatched(OrderPaid::class, fn ($event) => $event->order->is($paid) && $event->order->isPaid());
        Event::assertDispatched(OrderCancelled::class, fn ($event) => $event->order->is($cancelled) && $event->reason === 'expired');
    }

    #[Test]
    public function a_failed_payment_cancels_the_order_and_marks_the_payment_failed(): void
    {
        [$order, $payment] = $this->placeOrder(quantity: 2, stock: 5);

        app(OrderLifecycle::class)->markPaymentFailed($order, $payment);

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(OrderPaymentStatus::Failed, $order->payment_status);
        $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);
        $this->assertSame(5, InventoryItem::sole()->quantity);
    }

    #[Test]
    public function cancelling_an_order_closes_the_stripe_session(): void
    {
        [$order, $payment] = $this->placeOrder();

        app(OrderLifecycle::class)->cancel($order, 'expired');

        $this->assertSame([$payment->provider_reference], $this->stripe->expired);
    }

    #[Test]
    public function a_shop_with_no_tracked_stock_still_cancels_cleanly(): void
    {
        $product = Product::factory()->priced('5.00')->create();
        $this->cartWith($product, 2);
        $this->post(route('shop.checkout.store'), $this->checkoutPayload(['email' => 'a@example.com']));
        $order = Order::sole();

        $this->assertTrue(app(OrderLifecycle::class)->cancel($order, 'test'));
        $this->assertSame(0, InventoryMovement::count());
    }
}
