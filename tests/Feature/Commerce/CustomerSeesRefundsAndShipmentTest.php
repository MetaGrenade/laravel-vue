<?php

namespace Tests\Feature\Commerce;

use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderShipped;
use App\Notifications\RefundIssued;
use App\Support\Commerce\Money;
use App\Support\Commerce\OrderLifecycle;
use App\Support\Commerce\OrderRefunder;
use App\Support\Commerce\RefundRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithCommerce;
use Tests\TestCase;

/**
 * What a customer sees once staff have shipped or refunded their order, on the
 * receipt page, in their order history and in the emails.
 */
class CustomerSeesRefundsAndShipmentTest extends TestCase
{
    use InteractsWithCommerce;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerce();
        Notification::fake();
    }

    private function receipt(Order $order): string
    {
        return URL::signedRoute('shop.checkout.complete', ['order' => $order->public_id]);
    }

    private function refund(Order $order, string $amount): void
    {
        $this->app->make(OrderRefunder::class)->request(
            $order,
            Money::parse($amount, $order->currency),
            new RefundRequest(token: (string) Str::uuid()),
        );
    }

    #[Test]
    public function the_receipt_shows_a_partial_refund(): void
    {
        [$order] = $this->placePaidOrder(price: '40.00');
        $this->refund($order, '15.00');

        $this->get($this->receipt($order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.payment_status', 'partially_refunded')
                ->where('order.payment_status_label', 'Partially refunded')
                ->where('order.refunded_total', '15.00')
                ->where('order.status', 'processing'));
    }

    #[Test]
    public function the_receipt_shows_an_order_refunded_in_full(): void
    {
        [$order] = $this->placePaidOrder(price: '40.00');
        $this->refund($order, '40.00');

        $this->get($this->receipt($order))
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.payment_status', 'refunded')
                ->where('order.refunded_total', '40.00')
                ->where('order.status', 'cancelled'));
    }

    #[Test]
    public function the_receipt_shows_tracking_once_the_order_has_shipped(): void
    {
        [$order] = $this->placePaidOrder();

        $this->get($this->receipt($order))->assertInertia(fn (Assert $page) => $page->where('order.shipment', null));

        $this->app->make(OrderLifecycle::class)->fulfil($order->fresh(), [
            'carrier' => 'DPD',
            'tracking_number' => '15501234',
            'tracking_url' => 'https://track.example.com/15501234',
        ]);

        $this->get($this->receipt($order))
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.status', 'completed')
                ->where('order.shipment.carrier', 'DPD')
                ->where('order.shipment.tracking_number', '15501234')
                ->where('order.shipment.tracking_url', 'https://track.example.com/15501234'));
    }

    #[Test]
    public function the_order_history_shows_what_was_refunded(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->forUser($user)->paid()->create(['grand_total' => '60.00']);
        $order->forceFill(['payment_status' => 'partially_refunded', 'refunded_total' => '25.00'])->save();

        $this->actingAs($user)->get(route('shop.orders'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('orders.data.0.payment_status', 'partially_refunded')
                ->where('orders.data.0.refunded_total', '25.00'));
    }

    #[Test]
    public function the_refund_email_says_how_much_and_links_to_the_order(): void
    {
        [$order] = $this->placePaidOrder(price: '40.00');
        $this->refund($order, '15.00');

        Notification::assertSentOnDemand(RefundIssued::class, function (RefundIssued $notification) use ($order) {
            $mail = $notification->toMail(new \stdClass);
            $text = implode("\n", $mail->introLines);

            return $mail->subject === "Your refund for order {$order->number}"
                && str_contains($text, 'refunded 15.00 USD')
                && str_contains($text, 'Refunded so far: 15.00 USD of 40.00 USD')
                && str_contains($mail->actionUrl, $order->public_id)
                && str_contains($mail->actionUrl, 'signature=');
        });
    }

    #[Test]
    public function the_refund_email_says_when_everything_has_been_returned(): void
    {
        [$order] = $this->placePaidOrder(price: '40.00');
        $this->refund($order, '40.00');

        Notification::assertSentOnDemand(RefundIssued::class, function (RefundIssued $notification) {
            return in_array('Your order has now been refunded in full.', $notification->toMail(new \stdClass)->introLines, true);
        });
    }

    #[Test]
    public function the_shipping_email_carries_the_tracking_details(): void
    {
        [$order] = $this->placePaidOrder();

        $this->app->make(OrderLifecycle::class)->fulfil($order->fresh(), [
            'carrier' => 'DPD',
            'tracking_number' => '15501234',
            'tracking_url' => 'https://track.example.com/15501234',
        ]);

        Notification::assertSentOnDemand(OrderShipped::class, function (OrderShipped $notification) use ($order) {
            $mail = $notification->toMail(new \stdClass);

            return $mail->subject === "Your order {$order->number} has shipped"
                && in_array('Carrier: DPD', $mail->introLines, true)
                && in_array('Tracking number: 15501234', $mail->introLines, true)
                && $mail->actionUrl === 'https://track.example.com/15501234';
        });
    }

    #[Test]
    public function the_shipping_email_links_to_the_order_when_there_is_no_tracking(): void
    {
        [$order] = $this->placePaidOrder();

        $this->app->make(OrderLifecycle::class)->fulfil($order->fresh());

        Notification::assertSentOnDemand(OrderShipped::class, function (OrderShipped $notification) use ($order) {
            $mail = $notification->toMail(new \stdClass);

            return str_contains($mail->actionUrl, $order->public_id) && str_contains($mail->actionUrl, 'signature=');
        });
    }
}
