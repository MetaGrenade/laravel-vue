<?php

namespace Tests\Feature\Commerce;

use App\Enums\OrderPaymentStatus;
use App\Models\Order;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\Ownership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithCommerce;
use Tests\TestCase;

class OrderAccessTest extends TestCase
{
    use InteractsWithCommerce;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerce();
        Notification::fake();
    }

    private function signedUrl(Order $order): string
    {
        return URL::signedRoute('shop.checkout.complete', ['order' => $order->public_id]);
    }

    #[Test]
    public function a_guest_can_open_their_confirmation_through_the_signed_link(): void
    {
        [$order, $payment] = $this->placeOrder(price: '25.00');
        $this->deliverStripeEvent($this->stripeEvent('checkout.session.completed', $this->sessionFor($payment)))->assertOk();

        $this->get($this->signedUrl($order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('commerce/CheckoutComplete')
                ->where('order.number', $order->number)
                ->where('order.payment_status', 'paid')
                ->where('order.grand_total', '25.00')
                ->where('order.items.0.quantity', 1));
    }

    #[Test]
    public function the_link_without_a_valid_signature_is_refused(): void
    {
        [$order] = $this->placeOrder();

        $this->get(route('shop.checkout.complete', ['order' => $order->public_id]))->assertForbidden();
        $this->get($this->signedUrl($order).'tampered')->assertForbidden();
        $this->get(str_replace($order->public_id, 'someone-elses-order', $this->signedUrl($order)))->assertNotFound();
    }

    #[Test]
    public function a_signed_link_only_opens_its_own_order(): void
    {
        [$mine] = $this->placeOrder();
        [$other] = $this->placeOrder();

        $mineUrl = $this->signedUrl($mine);
        $swapped = str_replace($mine->public_id, $other->public_id, $mineUrl);

        $this->get($swapped)->assertForbidden();
    }

    #[Test]
    public function the_signed_in_owner_can_open_it_without_a_signature(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->forUser($user)->create();

        $this->actingAs($user)
            ->get(route('shop.checkout.complete', ['order' => $order->public_id]))
            ->assertOk();
    }

    #[Test]
    public function another_signed_in_customer_cannot_open_it(): void
    {
        $owner = User::factory()->create();
        $order = Order::factory()->forUser($owner)->create();

        $this->actingAs(User::factory()->create())
            ->get(route('shop.checkout.complete', ['order' => $order->public_id]))
            ->assertForbidden();
    }

    #[Test]
    public function the_confirmation_page_is_not_indexed(): void
    {
        [$order] = $this->placeOrder();

        $this->get($this->signedUrl($order))->assertSee('noindex', false);
    }

    #[Test]
    public function opening_the_page_picks_up_a_payment_the_webhook_has_not_delivered_yet(): void
    {
        [$order, $payment] = $this->placeOrder();
        $this->sessionFor($payment); // paid at Stripe; no webhook yet

        $this->get($this->signedUrl($order))
            ->assertInertia(fn (Assert $page) => $page->where('order.payment_status', 'paid'));

        $this->assertSame(OrderPaymentStatus::Paid, $order->fresh()->payment_status);
    }

    #[Test]
    public function the_page_still_loads_if_the_provider_cannot_be_reached(): void
    {
        [$order] = $this->placeOrder();
        $this->stripe->failRetrieveWith = new \RuntimeException('down');

        $this->get($this->signedUrl($order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('order.status', 'pending'));
    }

    #[Test]
    public function the_receipt_keeps_working_when_the_shop_is_switched_off(): void
    {
        [$order] = $this->placeOrder();
        SystemSetting::set('website_sections', ['blog' => true, 'forum' => true, 'support' => true, 'commerce' => false]);

        $this->get($this->signedUrl($order))->assertOk();
        $this->get(route('shop.cart'))->assertNotFound();
    }

    #[Test]
    public function the_order_history_lists_only_the_customers_own_orders(): void
    {
        $user = User::factory()->create();
        $mine = Order::factory()->forUser($user)->create();
        Order::factory()->forUser(User::factory()->create())->create();
        Order::factory()->create(); // a guest order

        $this->actingAs($user)
            ->get(route('shop.orders'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('commerce/Orders')
                ->has('orders.data', 1)
                ->where('orders.data.0.number', $mine->number));
    }

    #[Test]
    public function the_order_history_requires_signing_in(): void
    {
        $this->get(route('shop.orders'))->assertRedirect(route('login'));
    }

    /*
    |--------------------------------------------------------------------------
    | Team-ready ownership (see docs: orders belong to an owner, not a user_id)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function access_follows_the_owner_not_the_user_id_column(): void
    {
        $user = User::factory()->create();
        $someoneElse = User::factory()->create();

        // Placed by `$user` but owned by someone else (as a team order would be).
        $placedByUserOwnedByOther = Order::factory()->create(['user_id' => $user->id]);
        $placedByUserOwnedByOther->assignOwner($someoneElse)->save();

        // Owned by `$user` with no acting user recorded.
        $ownedWithoutUserId = Order::factory()->create(['user_id' => null]);
        $ownedWithoutUserId->assignOwner($user)->save();

        $this->assertFalse(Gate::forUser($user)->allows('view', $placedByUserOwnedByOther));
        $this->assertTrue(Gate::forUser($user)->allows('view', $ownedWithoutUserId));
        $this->assertSame(
            [$ownedWithoutUserId->id],
            Order::query()->visibleTo($user)->pluck('id')->all(),
        );
    }

    #[Test]
    public function an_order_owned_by_another_kind_of_owner_is_not_visible_to_a_user(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create();
        // 1.1 will add teams; an owner of a different type must never match a user with the same id.
        $order->forceFill(['owner_type' => 'App\\Models\\Team', 'owner_id' => $user->id])->save();

        $this->assertFalse($order->isOwnedBy($user));
        $this->assertFalse(Gate::forUser($user)->allows('view', $order));
        $this->assertSame(0, Order::query()->visibleTo($user)->count());
    }

    #[Test]
    public function a_guest_order_has_no_owner_and_is_never_visible_through_the_policy(): void
    {
        $order = Order::factory()->create();

        $this->assertNull($order->owner_type);
        $this->assertFalse(Gate::forUser(User::factory()->create())->allows('view', $order));
        $this->assertFalse(Gate::allows('view', $order));
    }

    #[Test]
    public function in_1_0_a_user_is_their_only_owner(): void
    {
        $user = User::factory()->create();

        $this->assertSame([$user], Ownership::ownersFor($user));
        $this->assertSame($user, Ownership::newRecordOwnerFor($user));
    }
}
