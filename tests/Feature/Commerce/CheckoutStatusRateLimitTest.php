<?php

namespace Tests\Feature\Commerce;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithCommerce;
use Tests\TestCase;

/**
 * The confirmation page re-checks a pending payment each time it loads, so a
 * customer waiting on a slow payment must not be shut out by the (stricter)
 * billing limiter that also covers starting a checkout.
 */
class CheckoutStatusRateLimitTest extends TestCase
{
    use InteractsWithCommerce;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerce();
        Notification::fake();
    }

    private function statusUrl(Order $order): string
    {
        return URL::signedRoute('shop.checkout.complete', ['order' => $order->public_id]);
    }

    #[Test]
    public function waiting_for_a_slow_payment_after_starting_checkout_is_never_rate_limited(): void
    {
        // The requests that came right before already used the billing allowance:
        // the checkout POST (inside placeOrder) and the checkout page.
        [$order] = $this->placeOrder();
        $this->get(route('shop.checkout'))->assertOk();

        // The page polls about seven times in the first minute and a quarter (see CheckoutComplete.vue),
        // after the initial load that brought the customer back.
        for ($load = 1; $load <= 8; $load++) {
            $this->get($this->statusUrl($order))->assertOk();
        }
    }

    #[Test]
    public function an_exhausted_billing_allowance_does_not_block_the_status_page(): void
    {
        [$order] = $this->placeOrder();

        // Use up the shared billing allowance (10 a minute).
        $statuses = [];
        for ($i = 0; $i < 12; $i++) {
            $statuses[] = $this->get(route('shop.checkout'))->getStatusCode();
        }
        $this->assertContains(429, $statuses, 'the billing limiter is in effect');

        $this->get($this->statusUrl($order))->assertOk();
    }

    #[Test]
    public function the_status_page_still_has_a_limit_of_its_own(): void
    {
        [$order] = $this->placeOrder();

        for ($load = 1; $load <= 30; $load++) {
            $this->get($this->statusUrl($order))->assertOk();
        }

        $this->get($this->statusUrl($order))->assertStatus(429);
    }

    #[Test]
    public function the_limit_is_per_order(): void
    {
        [$first] = $this->placeOrder();
        [$second] = $this->placeOrder();

        for ($load = 1; $load <= 31; $load++) {
            $this->get($this->statusUrl($first));
        }
        $this->get($this->statusUrl($first))->assertStatus(429);

        $this->get($this->statusUrl($second))->assertOk();
    }
}
