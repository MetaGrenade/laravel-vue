<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Refund;
use App\Models\User;
use App\Notifications\OrderShipped;
use App\Notifications\RefundIssued;
use App\Payments\Exceptions\RefundRejected;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\Support\InteractsWithCommerce;
use Tests\TestCase;

class CommerceOrderManagementTest extends TestCase
{
    use InteractsWithCommerce;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->setUpCommerce();
        Notification::fake();
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('admin');
    }

    /**
     * Staff with a limited role. (The admin role passes every permission check, so it cannot test them.)
     *
     * @param  list<string>  $permissions
     */
    private function staffWith(array $permissions): User
    {
        $user = User::factory()->create()->assignRole('editor');
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function packer(): User
    {
        return $this->staffWith(['commerce.acp.view', 'commerce.acp.edit']);
    }

    private function accountant(): User
    {
        return $this->staffWith(['commerce.acp.view', 'commerce.acp.refund']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function refundPayload(array $overrides = []): array
    {
        return array_replace(['amount' => '5.00', 'token' => (string) Str::uuid()], $overrides);
    }

    // --- Access ---------------------------------------------------------------------------------

    #[Test]
    public function the_pages_need_a_signed_in_user_with_commerce_access(): void
    {
        [$order] = $this->placePaidOrder();

        $this->get(route('acp.commerce.orders.index'))->assertRedirect(route('login'));
        $this->get(route('acp.commerce.orders.show', $order))->assertRedirect(route('login'));

        $outsider = User::factory()->create();
        $this->actingAs($outsider)->get(route('acp.commerce.orders.index'))->assertForbidden();
        $this->actingAs($outsider)->get(route('acp.commerce.orders.show', $order))->assertForbidden();
    }

    #[Test]
    public function staff_who_may_only_view_cannot_change_anything(): void
    {
        [$order] = $this->placePaidOrder();
        $viewer = $this->staffWith(['commerce.acp.view']);

        $this->actingAs($viewer)->get(route('acp.commerce.orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can', ['edit' => false, 'refund' => false]));

        $this->actingAs($viewer)->post(route('acp.commerce.orders.fulfil', $order))->assertForbidden();
        $this->actingAs($viewer)->post(route('acp.commerce.orders.cancel', $order))->assertForbidden();
        $this->actingAs($viewer)->post(route('acp.commerce.orders.notes.store', $order), ['note' => 'x'])->assertForbidden();
        $this->actingAs($viewer)->post(route('acp.commerce.orders.check-payment', $order))->assertForbidden();
        $this->actingAs($viewer)->post(route('acp.commerce.orders.refunds.store', $order), $this->refundPayload())->assertForbidden();

        $this->assertSame(OrderStatus::Processing, $order->fresh()->status);
        $this->assertSame(0, Refund::count());
    }

    #[Test]
    public function editing_an_order_does_not_allow_refunding_it(): void
    {
        [$order] = $this->placePaidOrder();

        $this->actingAs($this->packer())
            ->post(route('acp.commerce.orders.refunds.store', $order), $this->refundPayload())
            ->assertForbidden();

        $this->assertSame(0, Refund::count());
        $this->assertSame([], $this->stripe->refunds);
    }

    #[Test]
    public function the_refund_permission_exists_and_the_admin_role_has_it(): void
    {
        $this->assertTrue(User::factory()->create()->assignRole('admin')->can('commerce.acp.refund'));
        $this->assertNotNull(Permission::findByName('commerce.acp.refund'));
        $this->assertFalse($this->packer()->can('commerce.acp.refund'));
    }

    // --- The list -------------------------------------------------------------------------------

    #[Test]
    public function the_list_shows_orders_newest_first(): void
    {
        [$first] = $this->placePaidOrder();
        [$second] = $this->placePaidOrder(price: '40.00');

        $this->actingAs($this->admin())->get(route('acp.commerce.orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('acp/CommerceOrders')
                ->has('orders.data', 2)
                ->where('orders.data.0.public_id', $second->public_id)
                ->where('orders.data.0.number', $second->number)
                ->where('orders.data.0.grand_total', '40.00')
                ->where('orders.data.0.status', 'processing')
                ->where('orders.data.0.payment_status', 'paid')
                ->where('orders.data.0.customer_email', 'buyer@example.com')
                ->where('orders.data.0.items_count', 1)
                ->where('orders.data.1.public_id', $first->public_id)
                ->where('orders.meta.total', 2)
                ->where('counts.processing', 2));
    }

    #[Test]
    public function the_list_can_be_filtered_and_searched(): void
    {
        [$paid] = $this->placePaidOrder(price: '10.00');
        [$unpaid] = $this->placeOrder(price: '20.00');
        [$refunded] = $this->placePaidOrder(price: '30.00');
        $refunded->forceFill(['payment_status' => OrderPaymentStatus::Refunded, 'customer_email' => 'refunded@example.com'])->save();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('acp.commerce.orders.index', ['status' => 'pending']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.public_id', $unpaid->public_id)
                ->where('filters.status', 'pending'));

        $this->get(route('acp.commerce.orders.index', ['payment_status' => 'refunded']))
            ->assertInertia(fn (Assert $page) => $page->has('orders.data', 1)->where('orders.data.0.public_id', $refunded->public_id));

        $this->get(route('acp.commerce.orders.index', ['search' => 'refunded@']))
            ->assertInertia(fn (Assert $page) => $page->has('orders.data', 1)->where('orders.data.0.public_id', $refunded->public_id));

        $this->get(route('acp.commerce.orders.index', ['search' => $paid->number]))
            ->assertInertia(fn (Assert $page) => $page->has('orders.data', 1)->where('orders.data.0.public_id', $paid->public_id));

        // An unknown status is ignored rather than hiding everything.
        $this->get(route('acp.commerce.orders.index', ['status' => 'bogus']))
            ->assertInertia(fn (Assert $page) => $page->has('orders.data', 3)->where('filters.status', ''));
    }

    #[Test]
    public function the_list_is_paginated(): void
    {
        Order::factory()->count(25)->create();

        $this->actingAs($this->admin())->get(route('acp.commerce.orders.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders.data', 20)
                ->where('orders.meta.last_page', 2)
                ->where('orders.meta.total', 25));

        $this->get(route('acp.commerce.orders.index', ['page' => 2]))
            ->assertInertia(fn (Assert $page) => $page->has('orders.data', 5));
    }

    // --- One order ------------------------------------------------------------------------------

    #[Test]
    public function an_order_page_shows_everything_staff_need(): void
    {
        [$order, $payment] = $this->placePaidOrder(price: '25.00', quantity: 2);
        $this->actingAs($this->admin())->post(route('acp.commerce.orders.refunds.store', $order), $this->refundPayload(['amount' => '10.00', 'note' => 'Damaged box']));

        $this->get(route('acp.commerce.orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('acp/CommerceOrderShow')
                ->where('order.number', $order->number)
                ->where('order.status', 'processing')
                ->where('order.payment_status', 'partially_refunded')
                ->where('order.grand_total', '50.00')
                ->where('order.refunded_total', '10.00')
                ->where('order.customer_email', 'buyer@example.com')
                ->where('order.shipping_address.line1', '1 Test Street')
                ->has('order.items', 1)
                ->where('order.items.0.quantity', 2)
                ->where('payments.0.status', 'succeeded')
                ->where('payments.0.reference', $payment->fresh()->provider_payment_id)
                ->where('payments.0.url', 'https://dashboard.stripe.com/test/payments/'.$payment->fresh()->provider_payment_id)
                ->where('refunds.0.amount', '10.00')
                ->where('refunds.0.status', 'succeeded')
                ->where('refunds.0.note', 'Damaged box')
                ->where('refunds.0.reference', 're_test_1')
                ->where('refunding.refundable', '40.00')
                ->where('refunding.can_refund', true)
                ->where('refunding.via_provider', true)
                ->where('refunding.provider_label', 'Stripe')
                ->where('actions.can_fulfil', true)
                ->where('actions.can_cancel', false)
                ->where('events.0.type', OrderEvent::REFUND_SUCCEEDED));
    }

    #[Test]
    public function an_unpaid_order_offers_cancelling_and_checking_not_refunding(): void
    {
        [$order] = $this->placeOrder();

        $this->actingAs($this->admin())->get(route('acp.commerce.orders.show', $order))
            ->assertInertia(fn (Assert $page) => $page
                ->where('actions.can_cancel', true)
                ->where('actions.can_check_payment', true)
                ->where('actions.can_fulfil', false)
                ->where('refunding.can_refund', false));
    }

    #[Test]
    public function an_order_that_was_paid_late_is_flagged(): void
    {
        [$order] = $this->placePaidOrder();
        $order->forceFill(['metadata' => [...($order->metadata ?? []), 'late_payment' => true]])->save();

        $this->actingAs($this->admin())->get(route('acp.commerce.orders.show', $order))
            ->assertInertia(fn (Assert $page) => $page->where('order.late_payment', true));
    }

    // --- Fulfilling -----------------------------------------------------------------------------

    #[Test]
    public function an_order_is_marked_fulfilled_with_its_tracking_and_the_customer_is_told(): void
    {
        [$order] = $this->placePaidOrder();
        $packer = $this->packer();

        $this->actingAs($packer)->post(route('acp.commerce.orders.fulfil', $order), [
            'carrier' => 'Royal Mail',
            'tracking_number' => 'AB123456789GB',
            'tracking_url' => 'https://track.example.com/AB123456789GB',
            'notify_customer' => true,
        ])->assertRedirect()->assertSessionHas('success');

        $order->refresh();
        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertNotNull($order->fulfilled_at);
        // Compared without regard to key order: MySQL's JSON type stores object keys in its own
        // order (shortest first), while SQLite, MariaDB and PostgreSQL's json keep them as written.
        $this->assertEquals([
            'carrier' => 'Royal Mail',
            'tracking_number' => 'AB123456789GB',
            'tracking_url' => 'https://track.example.com/AB123456789GB',
        ], $order->metadata['shipment']);

        $event = $order->events()->where('type', OrderEvent::FULFILLED)->sole();
        $this->assertSame($packer->id, $event->user_id);
        $this->assertSame('Marked as fulfilled — Royal Mail AB123456789GB', $event->message);

        Notification::assertSentOnDemand(OrderShipped::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'buyer@example.com');
    }

    #[Test]
    public function the_customer_is_not_told_when_staff_choose_not_to(): void
    {
        [$order] = $this->placePaidOrder();

        $this->actingAs($this->packer())->post(route('acp.commerce.orders.fulfil', $order), ['notify_customer' => false])->assertRedirect();

        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
        Notification::assertSentOnDemandTimes(OrderShipped::class, 0);
    }

    #[Test]
    public function an_order_needs_no_tracking_to_be_fulfilled(): void
    {
        [$order] = $this->placePaidOrder();

        $this->actingAs($this->packer())->post(route('acp.commerce.orders.fulfil', $order))->assertRedirect()->assertSessionHas('success');

        $order->refresh();
        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertArrayNotHasKey('shipment', $order->metadata ?? []);
    }

    #[Test]
    public function a_tracking_link_must_be_a_web_address(): void
    {
        [$order] = $this->placePaidOrder();

        $this->actingAs($this->packer())
            ->post(route('acp.commerce.orders.fulfil', $order), ['tracking_url' => 'javascript:alert(1)'])
            ->assertSessionHasErrors('tracking_url');

        $this->assertSame(OrderStatus::Processing, $order->fresh()->status);
    }

    #[Test]
    public function an_order_is_fulfilled_once(): void
    {
        [$order] = $this->placePaidOrder();
        $packer = $this->packer();

        $this->actingAs($packer)->post(route('acp.commerce.orders.fulfil', $order))->assertSessionHas('success');
        $this->post(route('acp.commerce.orders.fulfil', $order))->assertSessionHas('error');

        $this->assertSame(1, $order->events()->where('type', OrderEvent::FULFILLED)->count());
        Notification::assertSentOnDemandTimes(OrderShipped::class, 1);
    }

    #[Test]
    public function an_unpaid_order_cannot_be_fulfilled(): void
    {
        [$order] = $this->placeOrder();

        $this->actingAs($this->packer())->post(route('acp.commerce.orders.fulfil', $order))->assertSessionHas('error');

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    // --- Cancelling, notes, checking ------------------------------------------------------------

    #[Test]
    public function an_unpaid_order_can_be_cancelled_and_its_stock_comes_back(): void
    {
        [$order, , $product] = $this->placeOrder(quantity: 2, stock: 5);
        $this->assertSame(3, InventoryItem::sole()->quantity);
        $packer = $this->packer();

        $this->actingAs($packer)->post(route('acp.commerce.orders.cancel', $order))->assertSessionHas('success');

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(5, InventoryItem::sole()->quantity);
        $this->assertSame($product->id, InventoryItem::sole()->product_id);
        $this->assertSame($packer->id, $order->events()->where('type', OrderEvent::CANCELLED)->sole()->user_id);
    }

    #[Test]
    public function a_paid_order_cannot_be_cancelled_it_is_refunded_instead(): void
    {
        [$order] = $this->placePaidOrder();

        $this->actingAs($this->packer())->post(route('acp.commerce.orders.cancel', $order))->assertSessionHas('error');

        $this->assertSame(OrderStatus::Processing, $order->fresh()->status);
    }

    #[Test]
    public function staff_can_leave_notes_on_an_order(): void
    {
        [$order] = $this->placePaidOrder();
        $packer = $this->packer();

        $this->actingAs($packer)->post(route('acp.commerce.orders.notes.store', $order), ['note' => 'Customer phoned about the delivery date.'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $event = $order->events()->where('type', OrderEvent::NOTE)->sole();
        $this->assertSame('Customer phoned about the delivery date.', $event->message);
        $this->assertSame($packer->id, $event->user_id);

        $this->get(route('acp.commerce.orders.show', $order))
            ->assertInertia(fn (Assert $page) => $page
                ->where('events.0.type', 'note')
                ->where('events.0.by', $packer->nickname));
    }

    #[Test]
    public function a_note_cannot_be_empty_or_huge(): void
    {
        [$order] = $this->placePaidOrder();
        $packer = $this->packer();

        $this->actingAs($packer)->post(route('acp.commerce.orders.notes.store', $order), ['note' => ''])->assertSessionHasErrors('note');
        $this->post(route('acp.commerce.orders.notes.store', $order), ['note' => str_repeat('a', 1001)])->assertSessionHasErrors('note');

        $this->assertSame(0, $order->events()->where('type', OrderEvent::NOTE)->count());
    }

    #[Test]
    public function checking_the_payment_asks_the_provider_and_applies_what_it_says(): void
    {
        [$order, $payment] = $this->placeOrder();
        $this->sessionFor($payment); // The customer paid on Stripe, but the webhook never arrived.

        $this->actingAs($this->packer())->post(route('acp.commerce.orders.check-payment', $order))->assertSessionHas('success');

        $this->assertSame(OrderPaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()->status);
    }

    #[Test]
    public function checking_the_payment_reports_a_provider_that_cannot_be_reached(): void
    {
        [$order] = $this->placeOrder();
        $this->stripe->failRetrieveWith = new RuntimeException('Stripe is down');

        $this->actingAs($this->packer())->post(route('acp.commerce.orders.check-payment', $order))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'Stripe is down'));

        $this->assertSame(OrderPaymentStatus::Unpaid, $order->fresh()->payment_status);
    }

    #[Test]
    public function there_is_nothing_to_check_on_a_paid_order(): void
    {
        [$order] = $this->placePaidOrder();

        $this->actingAs($this->packer())->post(route('acp.commerce.orders.check-payment', $order))->assertSessionHas('error');
    }

    // --- Refunding ------------------------------------------------------------------------------

    #[Test]
    public function staff_with_the_refund_permission_can_refund_an_order(): void
    {
        [$order] = $this->placePaidOrder(price: '30.00');
        $accountant = $this->accountant();

        $this->actingAs($accountant)->post(route('acp.commerce.orders.refunds.store', $order), $this->refundPayload([
            'amount' => '12.50',
            'reason' => 'requested_by_customer',
            'note' => 'Arrived late',
        ]))->assertRedirect()->assertSessionHas('success', 'Refunded 12.50 USD.');

        $refund = Refund::sole();
        $this->assertSame($accountant->id, $refund->user_id);
        $this->assertSame('12.50', $refund->amount);
        $this->assertSame('Arrived late', $refund->note);
        $this->assertSame(OrderPaymentStatus::PartiallyRefunded, $order->fresh()->payment_status);
        Notification::assertSentOnDemandTimes(RefundIssued::class, 1);
    }

    #[Test]
    public function the_customer_email_can_be_switched_off(): void
    {
        [$order] = $this->placePaidOrder(price: '30.00');

        $this->actingAs($this->accountant())
            ->post(route('acp.commerce.orders.refunds.store', $order), $this->refundPayload(['notify_customer' => false]))
            ->assertSessionHas('success');

        Notification::assertSentOnDemandTimes(RefundIssued::class, 0);
    }

    #[Test]
    public function a_refund_made_by_hand_is_recorded_without_asking_stripe(): void
    {
        [$order] = $this->placePaidOrder(price: '30.00');

        $this->actingAs($this->accountant())
            ->post(route('acp.commerce.orders.refunds.store', $order), $this->refundPayload(['amount' => '30.00', 'manual' => true, 'note' => 'Cash']))
            ->assertSessionHas('success', 'Recorded a refund of 30.00 USD.');

        $this->assertSame([], $this->stripe->refunds);
        $this->assertNull(Refund::sole()->provider);
        $this->assertSame(OrderPaymentStatus::Refunded, $order->fresh()->payment_status);
    }

    #[Test]
    public function a_refund_above_the_balance_is_a_form_error(): void
    {
        [$order] = $this->placePaidOrder(price: '30.00');

        $this->actingAs($this->accountant())
            ->from(route('acp.commerce.orders.show', $order))
            ->post(route('acp.commerce.orders.refunds.store', $order), $this->refundPayload(['amount' => '30.01']))
            ->assertRedirect(route('acp.commerce.orders.show', $order))
            ->assertSessionHasErrors(['amount' => 'At most 30.00 USD can still be refunded.']);

        $this->assertSame(0, Refund::count());
    }

    #[Test]
    public function restocking_a_partial_refund_is_a_form_error_on_that_field(): void
    {
        [$order] = $this->placePaidOrder(price: '30.00');

        $this->actingAs($this->accountant())
            ->post(route('acp.commerce.orders.refunds.store', $order), $this->refundPayload(['restock' => true]))
            ->assertSessionHasErrors('restock');

        $this->assertSame(0, Refund::count());
    }

    #[Test]
    public function a_refund_form_must_be_well_formed(): void
    {
        [$order] = $this->placePaidOrder(price: '30.00');
        $accountant = $this->accountant();

        $this->actingAs($accountant)->post(route('acp.commerce.orders.refunds.store', $order), ['amount' => '5.00'])->assertSessionHasErrors('token');
        $this->post(route('acp.commerce.orders.refunds.store', $order), $this->refundPayload(['token' => 'not-a-uuid']))->assertSessionHasErrors('token');
        $this->post(route('acp.commerce.orders.refunds.store', $order), $this->refundPayload(['amount' => '5.123']))->assertSessionHasErrors('amount');
        $this->post(route('acp.commerce.orders.refunds.store', $order), $this->refundPayload(['amount' => '-5']))->assertSessionHasErrors('amount');
        $this->post(route('acp.commerce.orders.refunds.store', $order), $this->refundPayload(['amount' => 'abc']))->assertSessionHasErrors('amount');
        $this->post(route('acp.commerce.orders.refunds.store', $order), $this->refundPayload(['reason' => 'because']))->assertSessionHasErrors('reason');
        $this->post(route('acp.commerce.orders.refunds.store', $order), $this->refundPayload(['note' => str_repeat('a', 501)]))->assertSessionHasErrors('note');

        $this->assertSame(0, Refund::count());
    }

    #[Test]
    public function pressing_refund_twice_refunds_once(): void
    {
        [$order] = $this->placePaidOrder(price: '30.00');
        $payload = $this->refundPayload();
        $accountant = $this->accountant();

        $this->actingAs($accountant)->post(route('acp.commerce.orders.refunds.store', $order), $payload)->assertSessionHas('success');
        $this->post(route('acp.commerce.orders.refunds.store', $order), $payload);

        $this->assertSame(1, Refund::count());
        $this->assertSame('5.00', $order->fresh()->refunded_total);
    }

    #[Test]
    public function a_refund_stripe_refuses_is_reported_as_an_error(): void
    {
        [$order] = $this->placePaidOrder(price: '30.00');
        $this->stripe->failRefundWith = new RefundRejected('This charge was disputed.');

        $this->actingAs($this->accountant())
            ->post(route('acp.commerce.orders.refunds.store', $order), $this->refundPayload())
            ->assertSessionHas('error', 'The refund was refused: This charge was disputed.');

        $this->assertSame(RefundStatus::Failed, Refund::sole()->status);
        $this->assertSame(OrderPaymentStatus::Paid, $order->fresh()->payment_status);
    }

    #[Test]
    public function a_refund_stripe_has_not_confirmed_is_reported_as_pending_and_can_be_checked(): void
    {
        [$order] = $this->placePaidOrder(price: '30.00');
        $accountant = $this->accountant();
        $this->stripe->loseRefundReply = true;

        $this->actingAs($accountant)
            ->post(route('acp.commerce.orders.refunds.store', $order), $this->refundPayload())
            ->assertSessionHas('warning');

        $refund = Refund::sole();
        $this->assertSame(RefundStatus::Pending, $refund->status);

        $this->get(route('acp.commerce.orders.show', $order))
            ->assertInertia(fn (Assert $page) => $page
                ->where('refunds.0.status', 'pending')
                ->where('refunds.0.can_check', true));

        $this->post(route('acp.commerce.orders.refunds.check', [$order, $refund]))->assertSessionHas('success');

        $this->assertSame(RefundStatus::Succeeded, $refund->fresh()->status);
        $this->assertSame('5.00', $order->fresh()->refunded_total);
    }

    #[Test]
    public function checking_a_refund_that_is_still_pending_says_so(): void
    {
        [$order] = $this->placePaidOrder(price: '30.00');
        $this->stripe->nextRefundStatus = 'pending';

        $this->actingAs($this->accountant())->post(route('acp.commerce.orders.refunds.store', $order), $this->refundPayload());
        $refund = Refund::sole();

        $this->post(route('acp.commerce.orders.refunds.check', [$order, $refund]))->assertSessionHas('info');
        $this->assertSame(RefundStatus::Pending, $refund->fresh()->status);
    }

    #[Test]
    public function checking_a_refund_reports_a_provider_that_cannot_be_reached(): void
    {
        [$order] = $this->placePaidOrder(price: '30.00');
        $this->stripe->nextRefundStatus = 'pending';
        $accountant = $this->accountant();
        $this->actingAs($accountant)->post(route('acp.commerce.orders.refunds.store', $order), $this->refundPayload());
        $this->stripe->failListRefundsWith = new RuntimeException('Stripe is down');

        $this->post(route('acp.commerce.orders.refunds.check', [$order, Refund::sole()]))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'Stripe is down'));
    }

    #[Test]
    public function a_refund_can_only_be_checked_through_its_own_order(): void
    {
        [$order] = $this->placePaidOrder(price: '30.00');
        [$other] = $this->placePaidOrder(price: '30.00');
        $accountant = $this->accountant();
        $this->actingAs($accountant)->post(route('acp.commerce.orders.refunds.store', $order), $this->refundPayload());

        $this->post(route('acp.commerce.orders.refunds.check', [$other, Refund::sole()]))->assertNotFound();
    }

    #[Test]
    public function checking_a_refund_needs_the_refund_permission(): void
    {
        [$order] = $this->placePaidOrder(price: '30.00');
        $this->actingAs($this->accountant())->post(route('acp.commerce.orders.refunds.store', $order), $this->refundPayload());

        $this->actingAs($this->packer())->post(route('acp.commerce.orders.refunds.check', [$order, Refund::sole()]))->assertForbidden();
    }

    // --- The overview ---------------------------------------------------------------------------

    #[Test]
    public function revenue_counts_only_paid_orders_less_what_was_refunded(): void
    {
        [$paid] = $this->placePaidOrder(price: '100.00');
        $this->placePaidOrder(price: '50.00');
        $this->placeOrder(price: '999.00'); // Never paid: not revenue.
        $this->actingAs($this->accountant())->post(route('acp.commerce.orders.refunds.store', $paid), $this->refundPayload(['amount' => '30.00']));

        $this->actingAs($this->admin())->get(route('acp.commerce.index'))
            ->assertInertia(fn (Assert $page) => $page->where('metrics.orders.revenue', 120));
    }

    #[Test]
    public function the_overview_links_each_recent_order_to_its_page(): void
    {
        [$order] = $this->placePaidOrder();

        $this->actingAs($this->admin())->get(route('acp.commerce.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('orders.0.public_id', $order->public_id)
                ->where('orders.0.number', $order->number));
    }
}
