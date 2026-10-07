<?php

namespace Tests\Feature\Commerce;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Jobs\ExpirePendingOrders;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Support\Commerce\PendingOrderExpirer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\InteractsWithCommerce;
use Tests\TestCase;

class PendingOrderExpiryTest extends TestCase
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
    public function an_order_is_held_for_the_payment_window(): void
    {
        config(['commerce.checkout.payment_window_minutes' => 45]);

        [$order] = $this->placeOrder();

        $this->assertEqualsWithDelta(45 * 60, now()->diffInSeconds($order->expires_at, absolute: true), 5);
    }

    #[Test]
    public function the_window_is_never_shorter_than_stripes_minimum(): void
    {
        $this->assertGreaterThanOrEqual(30, (int) config('commerce.checkout.payment_window_minutes'));
    }

    #[Test]
    public function unpaid_orders_past_their_window_are_cancelled_and_their_stock_returned(): void
    {
        [$order] = $this->placeOrder(quantity: 2, stock: 5);

        $this->travel(61)->minutes();
        $result = app(PendingOrderExpirer::class)->run();

        $this->assertSame(['cancelled' => 1, 'paid' => 0, 'skipped' => 0], $result);
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(5, InventoryItem::sole()->quantity);
    }

    #[Test]
    public function orders_still_inside_their_window_are_left_alone(): void
    {
        [$order] = $this->placeOrder(quantity: 2, stock: 5);

        $this->travel(10)->minutes();
        $result = app(PendingOrderExpirer::class)->run();

        $this->assertSame(0, $result['cancelled']);
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertSame(3, InventoryItem::sole()->quantity);
    }

    #[Test]
    public function paid_orders_never_expire(): void
    {
        [$order, $payment] = $this->placeOrder();
        $this->deliverStripeEvent($this->stripeEvent('checkout.session.completed', $this->sessionFor($payment)))->assertOk();

        $this->travel(5)->days();
        app(PendingOrderExpirer::class)->run();

        $this->assertSame(OrderStatus::Processing, $order->fresh()->status);
    }

    #[Test]
    public function an_order_paid_just_before_expiry_is_settled_not_cancelled(): void
    {
        // The customer paid but the webhook never arrived.
        [$order, $payment] = $this->placeOrder(quantity: 2, stock: 5);
        $this->sessionFor($payment);

        $this->travel(61)->minutes();
        $result = app(PendingOrderExpirer::class)->run();

        $this->assertSame(['cancelled' => 0, 'paid' => 1, 'skipped' => 0], $result);
        $this->assertSame(OrderStatus::Processing, $order->fresh()->status);
        $this->assertSame(OrderPaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertSame(3, InventoryItem::sole()->quantity);
    }

    #[Test]
    public function an_order_is_not_cancelled_while_the_provider_cannot_be_asked(): void
    {
        [$order] = $this->placeOrder(quantity: 2, stock: 5);
        $this->stripe->failRetrieveWith = new RuntimeException('Stripe is unreachable');

        $this->travel(61)->minutes();
        $result = app(PendingOrderExpirer::class)->run();

        $this->assertSame(['cancelled' => 0, 'paid' => 0, 'skipped' => 1], $result);
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status, 'it may have been paid; try again next time');

        // Once Stripe answers it is cancelled.
        $this->stripe->failRetrieveWith = null;
        $this->stripe->sessions[$order->payments()->value('provider_reference')]['status'] = 'expired';

        $this->assertSame(1, app(PendingOrderExpirer::class)->run()['cancelled']);
        $this->assertSame(5, InventoryItem::sole()->quantity);
    }

    #[Test]
    public function an_order_that_never_got_a_payment_is_cancelled_too(): void
    {
        $order = Order::factory()->create(['expires_at' => now()->subMinute()]);

        $result = app(PendingOrderExpirer::class)->run();

        $this->assertSame(1, $result['cancelled']);
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    }

    #[Test]
    public function the_job_and_command_run_the_expiry(): void
    {
        [$first] = $this->placeOrder();
        $this->travel(2)->hours();

        (new ExpirePendingOrders)->handle(app(PendingOrderExpirer::class));
        $this->assertSame(OrderStatus::Cancelled, $first->fresh()->status);

        [$second] = $this->placeOrder();
        $this->travel(2)->hours();

        $this->artisan('commerce:expire-orders')->assertSuccessful();
        $this->assertSame(OrderStatus::Cancelled, $second->fresh()->status);
    }

    #[Test]
    public function the_expiry_job_is_scheduled(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('ExpirePendingOrders')->assertSuccessful();
    }
}
