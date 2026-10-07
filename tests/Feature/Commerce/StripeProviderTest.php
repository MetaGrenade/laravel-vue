<?php

namespace Tests\Feature\Commerce;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Payments\Data\CheckoutContext;
use App\Payments\Exceptions\PaymentException;
use App\Payments\PaymentManager;
use App\Payments\Providers\StripeProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\InteractsWithCommerce;
use Tests\TestCase;

class StripeProviderTest extends TestCase
{
    use InteractsWithCommerce;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerce();
    }

    private function provider(): StripeProvider
    {
        $provider = app(PaymentManager::class)->provider('stripe');
        $this->assertInstanceOf(StripeProvider::class, $provider);

        return $provider;
    }

    private function context(): CheckoutContext
    {
        return new CheckoutContext('https://shop.test/done', 'https://shop.test/cart', now()->addHour());
    }

    private function orderWithLine(string $unitPrice, int $quantity, string $grandTotal, string $currency = 'USD'): Order
    {
        $order = Order::factory()->create([
            'currency' => $currency,
            'subtotal' => $grandTotal,
            'grand_total' => $grandTotal,
            'customer_email' => 'buyer@example.com',
        ]);
        $order->items()->create([
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'subtotal' => $unitPrice,
            'description' => 'Widget',
        ]);

        return $order;
    }

    #[Test]
    public function it_refuses_to_start_a_checkout_that_would_charge_a_different_total(): void
    {
        // The order says 12.00, but its lines only add up to 10.00 (for example a
        // shipping charge that was never sent to Stripe as its own line).
        $order = $this->orderWithLine('10.00', 1, '12.00');

        try {
            $this->provider()->startCheckout($order, $this->context());
            $this->fail('A mismatched total must not reach Stripe.');
        } catch (PaymentException $exception) {
            $this->assertStringContainsString($order->number, $exception->getMessage());
        }

        $this->assertSame([], $this->stripe->created, 'Stripe was never called');
    }

    #[Test]
    public function line_amounts_are_sent_in_exact_minor_units(): void
    {
        $order = $this->orderWithLine('19.99', 3, '59.97');

        $session = $this->provider()->startCheckout($order, $this->context());

        $line = $this->stripe->lastCreatedParams()['line_items'][0];
        $this->assertSame(1999, $line['price_data']['unit_amount']);
        $this->assertSame(3, $line['quantity']);
        $this->assertSame('usd', $line['price_data']['currency']);
        $this->assertSame('cs_test_1', $session->reference);
        $this->assertSame('stripe', $session->provider);
    }

    #[Test]
    public function zero_decimal_currencies_are_not_multiplied_by_a_hundred(): void
    {
        config(['commerce.currency' => 'JPY']);
        $order = $this->orderWithLine('1500.00', 2, '3000.00', 'JPY');

        $this->provider()->startCheckout($order, $this->context());

        $line = $this->stripe->lastCreatedParams()['line_items'][0];
        $this->assertSame(1500, $line['price_data']['unit_amount']);
        $this->assertSame('jpy', $line['price_data']['currency']);
    }

    #[Test]
    public function a_stripe_failure_becomes_a_payment_exception_without_changing_the_order(): void
    {
        $order = $this->orderWithLine('10.00', 1, '10.00');
        $this->stripe->failCreateWith = new RuntimeException('No such price');

        try {
            $this->provider()->startCheckout($order, $this->context());
            $this->fail('expected a PaymentException');
        } catch (PaymentException $exception) {
            $this->assertInstanceOf(RuntimeException::class, $exception->getPrevious());
        }

        $this->assertSame('pending', $order->fresh()->status->value);
        $this->assertSame(0, Payment::count());
    }

    #[Test]
    public function the_session_is_created_with_a_stable_idempotency_key(): void
    {
        $order = $this->orderWithLine('10.00', 1, '10.00');

        $this->provider()->startCheckout($order, $this->context());
        $this->provider()->startCheckout($order, $this->context());

        $this->assertCount(1, $this->stripe->created, 'a repeated request returns the same session');
        $this->assertSame("order-{$order->public_id}-checkout", $this->stripe->created[0]['key']);
    }

    #[Test]
    public function checkout_works_with_a_zero_decimal_store_end_to_end(): void
    {
        config(['commerce.currency' => 'JPY']);
        $product = Product::factory()->priced('1500', 'JPY')->stocked(5)->create();
        $this->cartWith($product, 2);

        $this->post(route('shop.checkout.store'), $this->checkoutPayload(['email' => 'a@example.com']))->assertRedirect();

        $order = Order::sole();
        $this->assertSame('JPY', $order->currency);
        $this->assertSame('3000.00', $order->grand_total);
        $this->assertSame(1500, $this->stripe->lastCreatedParams()['line_items'][0]['price_data']['unit_amount']);

        // Stripe reports 3000 (not 300000) for a yen order, and the order is accepted.
        $payment = $order->payments()->firstOrFail();
        $this->deliverStripeEvent($this->stripeEvent('checkout.session.completed', $this->sessionFor($payment)))->assertOk();

        $this->assertTrue($order->fresh()->isPaid());
    }
}
