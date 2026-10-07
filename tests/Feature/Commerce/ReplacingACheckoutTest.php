<?php

namespace Tests\Feature\Commerce;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\OrderCancelled;
use App\Models\Cart;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\InteractsWithCommerce;
use Tests\TestCase;

/**
 * A cart has at most one payable checkout at a time. Starting again must close
 * the earlier checkout at the provider, and confirm it, before a replacement is
 * offered; otherwise a shopper could pay both.
 */
class ReplacingACheckoutTest extends TestCase
{
    use InteractsWithCommerce;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerce();
        Notification::fake();
    }

    private function submit(): TestResponse
    {
        return $this->post(route('shop.checkout.store'), $this->checkoutPayload());
    }

    /**
     * A cart with a checkout already started for it.
     *
     * @return array{0: Order, 1: Payment, 2: Product}
     */
    private function startedCheckout(int $quantity = 2, int $stock = 10): array
    {
        return $this->placeOrder(price: '10.00', quantity: $quantity, stock: $stock);
    }

    #[Test]
    public function the_earlier_session_is_closed_before_the_replacement_is_created(): void
    {
        [$first, $firstPayment] = $this->startedCheckout();

        $this->submit()->assertRedirect();

        $this->assertSame(
            ["create:{$firstPayment->provider_reference}", "expire:{$firstPayment->provider_reference}", 'create:cs_test_2'],
            array_values(array_filter($this->stripe->calls, fn (string $call) => str_starts_with($call, 'create:') || str_starts_with($call, 'expire:'))),
            'the old session must be confirmed closed before Stripe is asked for a new one',
        );
        $this->assertSame(OrderStatus::Cancelled, $first->fresh()->status);
    }

    #[Test]
    public function the_earlier_session_is_closed_even_if_the_queued_listener_never_runs(): void
    {
        // Simulates a delayed or failed queue worker: no listener runs for the cancellation.
        Event::fake([OrderCancelled::class]);
        [, $firstPayment] = $this->startedCheckout();

        $this->submit()->assertRedirect();

        $this->assertSame([$firstPayment->provider_reference], $this->stripe->expired);
        $this->assertSame('expired', $this->stripe->sessions[$firstPayment->provider_reference]['status']);
        $this->assertSame('open', $this->stripe->sessions['cs_test_2']['status'], 'only the replacement is payable');
    }

    #[Test]
    public function only_one_session_is_ever_open_for_a_cart(): void
    {
        $this->startedCheckout();

        $this->submit();
        $this->submit();
        $this->submit();

        $open = collect($this->stripe->sessions)->where('status', 'open');

        $this->assertCount(1, $open);
        $this->assertSame(1, Order::where('status', OrderStatus::Pending->value)->count());
    }

    #[Test]
    public function the_first_checkout_for_a_cart_does_not_call_stripe_to_close_anything(): void
    {
        $this->startedCheckout();

        $this->assertSame([], $this->stripe->expired);
        $this->assertSame([], array_filter($this->stripe->calls, fn (string $call) => str_starts_with($call, 'expire:')));
    }

    #[Test]
    public function nothing_is_replaced_when_stripe_cannot_confirm_the_old_session_is_closed(): void
    {
        [$first, $firstPayment, $product] = $this->startedCheckout(quantity: 2, stock: 10);
        $this->stripe->failExpireWith = new RuntimeException('connection reset');
        $this->stripe->failRetrieveWith = new RuntimeException('connection reset');

        $this->submit()->assertRedirect(route('shop.cart'))->assertSessionHas('error');

        $this->assertStringContainsString('earlier checkout', (string) session('error'));
        $this->assertSame(OrderStatus::Pending, $first->fresh()->status, 'the old order is left exactly as it was');
        $this->assertSame(PaymentStatus::Pending, $firstPayment->fresh()->status);
        $this->assertSame(1, Order::count(), 'no replacement order was placed');
        $this->assertSame(1, Payment::count());
        $this->assertCount(1, array_filter($this->stripe->calls, fn (string $call) => str_starts_with($call, 'create:')), 'no second session was created');
        $this->assertSame(8, $product->inventoryItems()->sole()->quantity, 'the old order keeps its stock');
    }

    #[Test]
    public function an_open_session_that_cannot_be_expired_blocks_the_replacement(): void
    {
        [$first] = $this->startedCheckout();
        // Expiring fails, yet Stripe reports the session as still open.
        $this->stripe->failExpireWith = new RuntimeException('rate limited');

        $this->submit()->assertRedirect(route('shop.cart'))->assertSessionHas('error');

        $this->assertSame(OrderStatus::Pending, $first->fresh()->status);
        $this->assertSame(1, Order::count());
        $this->assertSame('open', $this->stripe->sessions['cs_test_1']['status']);
    }

    #[Test]
    public function an_earlier_checkout_that_was_paid_is_shown_not_replaced(): void
    {
        [$first, $firstPayment, $product] = $this->startedCheckout(quantity: 2, stock: 10);
        // The customer paid on Stripe; the webhook has not reached us yet.
        $this->stripe->pay($firstPayment->provider_reference);

        $response = $this->submit();

        $response->assertRedirect();
        $this->assertStringContainsString('/checkout/complete/'.$first->public_id, (string) $response->headers->get('Location'));

        $first->refresh();
        $this->assertSame(OrderPaymentStatus::Paid, $first->payment_status);
        $this->assertSame(OrderStatus::Processing, $first->status);
        $this->assertSame(1, Order::count(), 'no second order');
        $this->assertCount(1, array_filter($this->stripe->calls, fn (string $call) => str_starts_with($call, 'create:')), 'no second session');
        $this->assertSame(8, $product->inventoryItems()->sole()->quantity, 'stock is taken once');
    }

    #[Test]
    public function a_payment_that_is_still_settling_blocks_a_new_checkout(): void
    {
        [$first, $firstPayment] = $this->startedCheckout();
        // Completed on Stripe's side, but a bank debit has not been confirmed yet.
        $this->stripe->sessions[$firstPayment->provider_reference]['status'] = 'complete';

        $this->submit()->assertRedirect(route('shop.cart'))->assertSessionHas('error');

        $this->assertStringContainsString('still being confirmed', (string) session('error'));
        $this->assertSame(OrderStatus::Pending, $first->fresh()->status);
        $this->assertSame(1, Order::count());
    }

    #[Test]
    public function a_payment_held_for_review_blocks_a_new_checkout(): void
    {
        [$first, $firstPayment] = $this->startedCheckout();
        $firstPayment->update(['status' => PaymentStatus::Review]);

        $this->submit()->assertSessionHas('error');

        $this->assertStringContainsString('reviewed', (string) session('error'));
        $this->assertSame(OrderStatus::Pending, $first->fresh()->status);
        $this->assertSame(1, Order::count());
    }

    #[Test]
    public function a_replacement_that_fails_leaves_the_earlier_order_cancelled_and_its_stock_free(): void
    {
        [$first, , $product] = $this->startedCheckout(quantity: 2, stock: 10);
        // Other sales (and a correction) leave less than the cart needs, even once the
        // old order gives its 2 units back.
        $product->inventoryItems()->update(['quantity' => -1]);

        $this->submit()->assertSessionHas('error');

        // The old session is confirmed closed and its order cancelled, so the cart
        // is not left with an unpayable order quietly holding stock.
        $this->assertSame(OrderStatus::Cancelled, $first->fresh()->status);
        $this->assertSame(1, Order::count(), 'no replacement order');
        $this->assertSame(1, $product->inventoryItems()->sole()->quantity, 'the old order released its stock');
        $this->assertSame('expired', $this->stripe->sessions['cs_test_1']['status']);
    }

    #[Test]
    public function two_checkouts_for_one_cart_cannot_run_at_once(): void
    {
        [, , $product] = $this->startedCheckout();
        $cart = Cart::query()->latest('id')->firstOrFail();

        // Another request is in the middle of starting this cart's checkout.
        $held = Cache::lock('checkout:cart:'.$cart->id, 30);
        $this->assertTrue($held->get());

        try {
            $this->submit()->assertRedirect(route('shop.cart'))->assertSessionHas('error');
        } finally {
            $held->release();
        }

        $this->assertStringContainsString('already being prepared', (string) session('error'));
        $this->assertSame(1, Order::count());
        $this->assertSame([], $this->stripe->expired, 'nothing was closed or replaced');
        $this->assertSame(8, InventoryItem::sole()->quantity);
    }

    #[Test]
    public function the_lock_is_released_after_a_checkout_so_the_next_one_can_start(): void
    {
        $this->startedCheckout();

        $this->submit()->assertRedirect();
        $this->submit()->assertRedirect();

        $this->assertSame(1, Order::where('status', OrderStatus::Pending->value)->count());
    }

    #[Test]
    public function the_lock_is_released_even_when_checkout_fails(): void
    {
        [, , $product] = $this->startedCheckout();
        $this->stripe->failCreateWith = new RuntimeException('down');

        $this->submit()->assertSessionHas('error');

        $this->stripe->failCreateWith = null;
        $this->submit()->assertRedirect();

        $this->assertSame(1, Order::where('status', OrderStatus::Pending->value)->count());
    }
}
