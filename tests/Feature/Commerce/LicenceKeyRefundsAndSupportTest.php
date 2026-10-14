<?php

namespace Tests\Feature\Commerce;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\LicenceKey;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Product;
use App\Models\User;
use App\Support\Commerce\Digital\LicenceKeys;
use App\Support\Commerce\Money;
use App\Support\Commerce\OrderLifecycle;
use App\Support\Commerce\OrderRefunder;
use App\Support\Commerce\RefundRequest;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithCommerce;
use Tests\Support\SellsLicenceKeys;
use Tests\TestCase;

/**
 * What happens to licence keys when money goes back, and when staff take a key away from a customer.
 * A key the customer was given is never sold again, so none of this ever puts one back in the pool.
 */
class LicenceKeyRefundsAndSupportTest extends TestCase
{
    use InteractsWithCommerce;
    use RefreshDatabase;
    use SellsLicenceKeys;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->setUpCommerce();
        $this->seed(RolePermissionSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('admin');
    }

    private function refund(Order $order, ?string $amount = null, bool $restock = false): void
    {
        Notification::fake();

        $this->app->make(OrderRefunder::class)->request(
            $order->refresh(),
            Money::parse($amount ?? $order->grand_total, 'USD'),
            new RefundRequest(token: (string) Str::uuid(), restock: $restock),
        );
    }

    private function failTheRefund(string $id = 're_test_1'): void
    {
        $this->deliverStripeEvent($this->stripeEvent('refund.failed', $this->stripe->setRefundStatus($id, 'failed', 'expired_or_canceled_card')))->assertOk();
    }

    // --- Refunds -----------------------------------------------------------------------------------

    #[Test]
    public function a_refund_in_full_takes_the_keys_away_from_the_customer_but_not_back_into_the_pool(): void
    {
        $product = $this->keyedProduct(3);
        $order = $this->buy($product, 2);
        $this->assertCount(2, $this->orderPage($order)->viewData('page')['props']['licenceKeys'][0]['keys']);

        $this->refund($order);

        $keys = LicenceKey::query()->whereNotNull('order_item_id')->get();
        $this->assertCount(2, $keys);
        $this->assertSame([LicenceKey::BY_REFUND], $keys->pluck('revoked_reason')->unique()->all());
        $this->assertSame(1, LicenceKey::query()->unused()->count(), 'the keys were seen by the customer, so they are never resold');
        $this->orderPage($order)->assertInertia(fn (Assert $page) => $page->where('licenceKeys', []));
        $this->assertStockFollowsPool($product);
    }

    #[Test]
    public function returning_the_items_to_stock_does_not_return_keys_the_customer_was_given(): void
    {
        $product = $this->keyedProduct(3);
        $order = $this->buy($product, 2);
        $this->assertSame(1, $this->stockOf($product)->quantity);

        $this->refund($order, restock: true);

        $this->assertSame(1, $this->stockOf($product)->quantity, 'two keys were delivered and are spent: no unit goes back on the shelf');
        $this->assertSame(0, InventoryMovement::query()->where('reason', InventoryMovement::RESTOCK)->count());
        $this->assertSame(OrderStatus::Completed, $order->fresh()->status, 'it was delivered, so the refund leaves it completed');
        $this->assertStockFollowsPool($product);
    }

    #[Test]
    public function an_order_that_was_never_given_its_key_does_get_its_unit_back_on_a_restocking_refund(): void
    {
        [$product, $late] = $this->lateOrderWithNoKeyLeft();
        $this->assertSame(-1, $this->stockOf($product)->quantity);

        $this->refund($late, restock: true);

        $this->assertSame(0, $this->stockOf($product)->quantity, 'nothing was delivered, so the unit it was owed is free again');
        $this->assertStockFollowsPool($product);
    }

    #[Test]
    public function a_failed_refund_takes_the_unit_of_an_order_owed_a_key_again(): void
    {
        [$product, $late] = $this->lateOrderWithNoKeyLeft();

        // Refunded with its unit returned, then the refund fails: the order is owed its key once more.
        $this->refund($late, restock: true);
        $this->assertSame(0, $this->stockOf($product)->quantity);
        $this->failTheRefund();

        $this->assertSame(-1, $this->stockOf($product)->quantity, 'the order is owed its key again');
        $this->assertSame(OrderStatus::Processing, $late->fresh()->status);
        $this->assertStockFollowsPool($product);
    }

    #[Test]
    public function an_order_given_only_some_of_its_keys_gets_back_only_the_units_it_was_not_given(): void
    {
        // Two keys in the pool; one customer's checkout holds both, then expires.
        $product = $this->keyedProduct(2);
        $late = $this->buy($product, 2, pay: false);
        $session = $this->sessionFor($late->payments()->sole(), paid: false);
        $this->deliverStripeEvent($this->stripeEvent('checkout.session.expired', $session))->assertOk();

        // Another customer takes one of the keys, then the first customer's payment arrives after all.
        $this->buy($product);
        $this->deliverStripeEvent($this->stripeEvent('checkout.session.completed', $this->sessionFor($late->payments()->sole())))->assertOk();

        $late->refresh();
        $this->assertCount(1, $this->keysOf($late), 'only one key was left to give');
        $this->assertSame(-1, $this->stockOf($product)->quantity);
        $this->assertStockFollowsPool($product);

        // Refunded with the items returned: the one unit that was never delivered goes back, not both.
        $this->refund($late, restock: true);
        $this->assertSame(0, $this->stockOf($product)->quantity);

        // The refund fails: the order is owed that unit again, exactly one, not two.
        $this->failTheRefund();
        $this->assertSame(-1, $this->stockOf($product)->quantity);
        $this->assertStockFollowsPool($product);
    }

    // --- Stock that changed after the orders were placed ---------------------------------------------

    /**
     * Ordinary stock with an earlier order that was paid, then turned into a pool of keys.
     *
     * @return array{0: Product, 1: Order}
     */
    private function ordinaryProductWithAPaidOrderThenKeys(int $keys): array
    {
        $product = Product::factory()->priced('15.00')->digital()->stocked(5)->create();
        $earlier = $this->buy($product);
        $this->assertSame(OrderStatus::Processing, $earlier->status, 'nothing delivers it by itself, so it is still open');

        $stock = app(LicenceKeys::class)->enable($product, null);
        app(LicenceKeys::class)->add($stock, implode("\n", array_map(fn (int $n) => "NEW-{$n}", range(1, $keys))));

        return [$product, $earlier];
    }

    #[Test]
    public function refunding_an_order_bought_before_the_stock_became_keys_does_not_add_a_unit_with_no_key(): void
    {
        [$product, $earlier] = $this->ordinaryProductWithAPaidOrderThenKeys(2);
        $this->assertSame(2, $this->stockOf($product)->quantity);

        $this->refund($earlier, restock: true);

        $this->assertSame(2, $this->stockOf($product)->quantity, 'the earlier order held an ordinary unit, which is not a key');
        $this->assertSame(0, InventoryMovement::query()->where('reason', InventoryMovement::RESTOCK)->count());
        $this->assertStockFollowsPool($product);

        // And if that refund does not hold, nothing is taken from the pool either.
        $this->failTheRefund();
        $this->assertSame(2, $this->stockOf($product)->quantity);
        $this->assertStockFollowsPool($product);
    }

    #[Test]
    public function a_payment_that_arrives_late_for_a_line_bought_before_the_stock_became_keys_takes_nothing_from_the_pool(): void
    {
        $product = Product::factory()->priced('15.00')->digital()->stocked(5)->create();
        $late = $this->buy($product, pay: false);
        $this->deliverStripeEvent($this->stripeEvent('checkout.session.expired', $this->sessionFor($late->payments()->sole(), paid: false)))->assertOk();

        $stock = app(LicenceKeys::class)->enable($product, null);
        app(LicenceKeys::class)->add($stock, "NEW-1\nNEW-2");

        $this->deliverStripeEvent($this->stripeEvent('checkout.session.completed', $this->sessionFor($late->payments()->sole())))->assertOk();

        $this->assertSame(OrderStatus::Processing, $late->fresh()->status, 'paid, and honoured');
        $this->assertSame(2, $this->stockOf($product)->quantity, 'its ordinary unit is not a key, so the pool is not charged for it');
        $this->assertStockFollowsPool($product);
    }

    // --- A refund ends what an order was owed ----------------------------------------------------------

    #[Test]
    public function a_full_refund_releases_the_key_an_order_was_still_owed_even_without_returning_goods_to_stock(): void
    {
        [$product, $late] = $this->lateOrderWithNoKeyLeft();
        $this->assertSame(-1, $this->stockOf($product)->quantity, 'the unit it is owed is spoken for');

        $this->refund($late); // not "put the items back in stock"

        $this->assertSame(0, $this->stockOf($product)->quantity, 'nothing is owed any more, so nothing stays spoken for');
        $this->assertStockFollowsPool($product);

        // The next key that is added can be sold, rather than being used up to reach zero.
        app(LicenceKeys::class)->add($this->stockOf($product), 'FRESH-1');

        $this->assertSame(1, $this->stockOf($product)->quantity);
        $this->assertSame(1, LicenceKey::query()->unused()->count());
        $this->assertSame([], $this->keysOf($late->refresh()), 'and the refunded order is not given it');
        $this->assertStockFollowsPool($product);
        $this->assertCount(1, $this->keysOf($this->buy($product)), 'a new customer can buy it');
    }

    #[Test]
    public function the_unit_is_held_again_if_the_refund_that_released_it_fails(): void
    {
        [$product, $late] = $this->lateOrderWithNoKeyLeft();

        $this->refund($late);
        $this->assertSame(0, $this->stockOf($product)->quantity);
        $this->failTheRefund();

        $this->assertSame(-1, $this->stockOf($product)->quantity, 'the order is owed its key again');
        $this->assertSame(OrderStatus::Processing, $late->fresh()->status);
        $this->assertStockFollowsPool($product);

        // Repeating the failure message does not take it twice.
        $this->failTheRefund();
        $this->assertSame(-1, $this->stockOf($product)->quantity);
    }

    #[Test]
    public function a_refund_without_restocking_leaves_ordinary_stock_exactly_as_it_was(): void
    {
        $product = Product::factory()->priced('15.00')->digital()->stocked(5)->create();
        $order = $this->buy($product);
        $this->assertSame(4, InventoryItem::sole()->quantity);

        $this->refund($order); // goods are not returned unless staff say so

        $this->assertSame(4, InventoryItem::sole()->quantity, 'only what an order was owed in keys is released, never goods');
    }

    #[Test]
    public function the_keys_come_back_when_the_refund_that_hid_them_fails(): void
    {
        $product = $this->keyedProduct(2);
        $order = $this->buy($product);
        $this->refund($order, restock: true);
        $this->orderPage($order)->assertInertia(fn (Assert $page) => $page->where('licenceKeys', []));

        $this->failTheRefund();

        $this->assertSame(OrderPaymentStatus::Paid, $order->fresh()->payment_status);
        $this->orderPage($order)->assertInertia(fn (Assert $page) => $page->where('licenceKeys.0.keys', ["KEY-1-{$product->id}"]));
        $this->assertSame(1, $this->stockOf($product)->quantity, 'delivered, so never restocked and nothing to take back');
        $this->assertStockFollowsPool($product);
    }

    #[Test]
    public function a_partial_refund_leaves_the_keys_where_they_are(): void
    {
        $product = $this->keyedProduct(2, price: '20.00');
        $order = $this->buy($product);

        $this->refund($order, '5.00');

        $this->orderPage($order)->assertInertia(fn (Assert $page) => $page->where('licenceKeys.0.keys', ["KEY-1-{$product->id}"]));
        $this->assertNull(LicenceKey::query()->whereNotNull('order_item_id')->sole()->revoked_at);
    }

    #[Test]
    public function a_refunded_order_is_never_topped_up_with_new_keys(): void
    {
        $product = $this->keyedProduct(2);
        $order = $this->buy($product);
        $this->refund($order);

        // More keys arrive and someone asks for the order's keys to be issued.
        app(LicenceKeys::class)->add($this->stockOf($product), "MORE-1\nMORE-2");
        $issued = app(OrderLifecycle::class)->issueKeys($order->refresh());
        $this->actingAs($this->admin())->post(route('acp.commerce.orders.licence-keys.issue', $order->public_id))->assertSessionHas('error');

        $this->assertSame(0, $issued);
        $this->assertSame(1, LicenceKey::query()->whereNotNull('order_item_id')->count());
        $this->assertSame(0, app(OrderLifecycle::class)->issueWaitingKeys($this->stockOf($product)));
    }

    // --- Taking a key back -----------------------------------------------------------------------

    #[Test]
    public function withdrawing_a_key_removes_it_from_the_customer_and_owes_them_another(): void
    {
        $product = $this->keyedProduct(2);
        $order = $this->buy($product);
        $key = LicenceKey::query()->whereNotNull('order_item_id')->sole();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('acp.commerce.orders.licence-keys.withdraw', ['order' => $order->public_id, 'key' => $key->id]))->assertSessionHas('success');

        $key->refresh();
        $this->assertSame(LicenceKey::BY_STAFF, $key->revoked_reason);
        $this->assertSame(1, LicenceKey::query()->unused()->count(), 'a withdrawn key is not reused');
        $this->orderPage($order)->assertInertia(fn (Assert $page) => $page
            ->where('licenceKeys.0.keys', [])
            ->where('licenceKeys.0.waiting', 1));
        $this->assertSame(0, $this->stockOf($product)->quantity, 'the unused key is spoken for by the order that is owed one');
        $this->assertStockFollowsPool($product);

        $movement = InventoryMovement::query()->where('note', 'Licence key withdrawn')->sole();
        $this->assertSame(-1, $movement->delta);
        $this->assertSame($admin->id, $movement->user_id);
        $event = $order->events()->where('type', OrderEvent::LICENCE_WITHDRAWN)->sole();
        $this->assertSame($admin->id, $event->user_id);
        $this->assertStringNotContainsString("KEY-1-{$product->id}", json_encode($event->toArray()), 'the history never holds a key');
    }

    #[Test]
    public function a_withdrawn_key_is_replaced_as_soon_as_the_pool_has_another(): void
    {
        $product = $this->keyedProduct(1);
        $order = $this->buy($product);
        $key = LicenceKey::query()->whereNotNull('order_item_id')->sole();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('acp.commerce.orders.licence-keys.withdraw', ['order' => $order->public_id, 'key' => $key->id]));
        $this->assertSame(OrderStatus::Completed, $order->fresh()->status, 'the order itself is left as it was');
        $this->assertStockFollowsPool($product);

        $this->actingAs($admin)->post(route('acp.commerce.licence-keys.store', $this->stockOf($product)->id), ['keys' => 'REPLACEMENT-1'])
            ->assertSessionHas('success');

        $this->orderPage($order)->assertInertia(fn (Assert $page) => $page
            ->where('licenceKeys.0.keys', ['REPLACEMENT-1'])
            ->where('licenceKeys.0.waiting', 0));
        $this->assertSame(0, $this->stockOf($product)->quantity);
        $this->assertStockFollowsPool($product);
    }

    #[Test]
    public function replacing_a_key_issues_another_at_once_and_uses_up_a_second_unit(): void
    {
        $product = $this->keyedProduct(3);
        $order = $this->buy($product);
        $old = LicenceKey::query()->whereNotNull('order_item_id')->sole();
        $this->assertSame(2, $this->stockOf($product)->quantity);

        $this->actingAs($this->admin())->post(route('acp.commerce.orders.licence-keys.replace', ['order' => $order->public_id, 'key' => $old->id]))->assertSessionHas('success', 'Key replaced.');

        $this->assertSame(1, $this->stockOf($product)->quantity);
        $this->assertSame(1, LicenceKey::query()->unused()->count());
        $this->orderPage($order)->assertInertia(fn (Assert $page) => $page
            ->where('licenceKeys.0.keys', ["KEY-2-{$product->id}"])
            ->where('licenceKeys.0.waiting', 0));
        $this->assertSame(['KEY-1-'.$product->id, 'KEY-2-'.$product->id], $this->keysOf($order), 'the old key stays on record, withdrawn');
        $this->assertStockFollowsPool($product);

        $movement = InventoryMovement::query()->where('note', 'Replacement licence key')->sole();
        $this->assertSame(-1, $movement->delta);
        $this->assertSame($order->id, $movement->order_id);
        $this->assertTrue($order->events()->where('type', OrderEvent::LICENCE_REPLACED)->exists());
    }

    #[Test]
    public function replacing_a_key_is_refused_when_there_is_no_key_to_replace_it_with(): void
    {
        $product = $this->keyedProduct(1);
        $order = $this->buy($product);
        $key = LicenceKey::query()->whereNotNull('order_item_id')->sole();

        $this->actingAs($this->admin())->post(route('acp.commerce.orders.licence-keys.replace', ['order' => $order->public_id, 'key' => $key->id]))->assertSessionHas('error');

        $this->assertNull($key->fresh()->revoked_at, 'nothing was taken away');
        $this->assertSame(0, $this->stockOf($product)->quantity);
        $this->assertSame(0, $order->events()->where('type', OrderEvent::LICENCE_REPLACED)->count());
        $this->orderPage($order)->assertInertia(fn (Assert $page) => $page->where('licenceKeys.0.keys', ["KEY-1-{$product->id}"]));
        $this->assertStockFollowsPool($product);
    }

    #[Test]
    public function a_replacement_cannot_use_a_key_that_orders_are_waiting_for(): void
    {
        $product = $this->keyedProduct(2);
        $order = $this->buy($product);
        $key = LicenceKey::query()->whereNotNull('order_item_id')->sole();
        // Someone else's order is waiting for payment and holds the only key left.
        $this->buy($product, pay: false);
        $this->assertSame(0, $this->stockOf($product)->quantity);

        $this->actingAs($this->admin())->post(route('acp.commerce.orders.licence-keys.replace', ['order' => $order->public_id, 'key' => $key->id]))->assertSessionHas('error');

        $this->assertNull($key->fresh()->revoked_at);
        $this->assertStockFollowsPool($product);
    }

    #[Test]
    public function after_a_replacement_the_spent_keys_still_stay_out_of_the_shelf_on_a_restocking_refund(): void
    {
        $product = $this->keyedProduct(3);
        $order = $this->buy($product);
        $old = LicenceKey::query()->whereNotNull('order_item_id')->sole();
        $this->actingAs($this->admin())->post(route('acp.commerce.orders.licence-keys.replace', ['order' => $order->public_id, 'key' => $old->id]));
        $this->assertSame(1, $this->stockOf($product)->quantity);

        $this->refund($order, restock: true);

        $this->assertSame(1, $this->stockOf($product)->quantity, 'two keys were handed out (one withdrawn): neither goes back');
        $this->assertSame(1, LicenceKey::query()->unused()->count());
        $this->assertStockFollowsPool($product);
    }

    #[Test]
    public function a_key_of_another_order_or_a_refunded_order_cannot_be_withdrawn(): void
    {
        $product = $this->keyedProduct(3);
        $first = $this->buy($product);
        $second = $this->buy($product);
        $firstKey = LicenceKey::query()->whereIn('order_item_id', $first->items()->pluck('id'))->sole();
        $admin = $this->admin();

        // Through the wrong order.
        $this->actingAs($admin)->post(route('acp.commerce.orders.licence-keys.withdraw', ['order' => $second->public_id, 'key' => $firstKey->id]))->assertNotFound();
        $this->assertNull($firstKey->fresh()->revoked_at);

        // A key already withdrawn.
        $this->actingAs($admin)->post(route('acp.commerce.orders.licence-keys.withdraw', ['order' => $first->public_id, 'key' => $firstKey->id]))->assertSessionHas('success');
        $this->actingAs($admin)->post(route('acp.commerce.orders.licence-keys.withdraw', ['order' => $first->public_id, 'key' => $firstKey->id]))->assertSessionHas('error');

        // An order refunded in full has no keys to withdraw.
        $secondKey = LicenceKey::query()->whereIn('order_item_id', $second->items()->pluck('id'))->sole();
        $this->refund($second);
        $this->actingAs($admin)->post(route('acp.commerce.orders.licence-keys.withdraw', ['order' => $second->public_id, 'key' => $secondKey->id]))->assertSessionHas('error');
        $this->assertSame(LicenceKey::BY_REFUND, $secondKey->fresh()->revoked_reason);
    }

    #[Test]
    public function a_refund_does_not_undo_a_key_staff_withdrew(): void
    {
        $product = $this->keyedProduct(3, price: '20.00');
        $order = $this->buy($product);
        $key = LicenceKey::query()->whereNotNull('order_item_id')->sole();
        $this->actingAs($this->admin())->post(route('acp.commerce.orders.licence-keys.withdraw', ['order' => $order->public_id, 'key' => $key->id]));

        // A partial refund recalculates the order, which restores only what a refund took away.
        $this->refund($order, '5.00');

        $this->assertSame(LicenceKey::BY_STAFF, $key->fresh()->revoked_reason);
    }

    // --- What staff see ------------------------------------------------------------------------------

    #[Test]
    public function staff_see_each_key_masked_and_what_the_order_is_still_owed(): void
    {
        $product = $this->keyedProduct(2);
        $order = $this->buy($product);

        $response = $this->actingAs($this->admin())->get(route('acp.commerce.orders.show', $order->public_id))
            ->assertInertia(fn (Assert $page) => $page
                ->has('licence_keys', 1)
                ->where('licence_keys.0.quantity', 1)
                ->where('licence_keys.0.waiting', 0)
                ->where('licence_keys.0.keys.0.state', 'issued')
                ->where('licence_keys.0.keys.0.masked', fn ($masked) => str_contains($masked, '•') && ! str_contains($masked, 'KEY'))
                ->where('actions.can_issue_keys', false));

        $this->assertStringNotContainsString("KEY-1-{$product->id}", $response->getContent());
    }

    #[Test]
    public function staff_are_told_when_an_order_is_waiting_for_a_key(): void
    {
        [, $late] = $this->lateOrderWithNoKeyLeft();

        $this->actingAs($this->admin())->get(route('acp.commerce.orders.show', $late->public_id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('licence_keys.0.waiting', 1)
                ->where('licence_keys.0.keys', [])
                ->where('actions.can_issue_keys', true)
                ->where('order.late_payment', true));
    }

    #[Test]
    public function an_order_without_keys_shows_no_key_card(): void
    {
        $plain = Product::factory()->priced('15.00')->stocked(3)->create();
        $order = $this->buy($plain);

        $this->actingAs($this->admin())->get(route('acp.commerce.orders.show', $order->public_id))
            ->assertInertia(fn (Assert $page) => $page->where('licence_keys', [])->where('actions.can_issue_keys', false));
    }

    #[Test]
    public function the_actions_need_the_edit_permission(): void
    {
        $product = $this->keyedProduct(2);
        $order = $this->buy($product);
        $key = LicenceKey::query()->whereNotNull('order_item_id')->sole();
        $viewer = User::factory()->create()->assignRole('editor');
        $viewer->givePermissionTo('commerce.acp.view');

        $this->actingAs($viewer)->post(route('acp.commerce.orders.licence-keys.withdraw', ['order' => $order->public_id, 'key' => $key->id]))->assertForbidden();
        $this->actingAs($viewer)->post(route('acp.commerce.orders.licence-keys.replace', ['order' => $order->public_id, 'key' => $key->id]))->assertForbidden();
        $this->actingAs($viewer)->post(route('acp.commerce.orders.licence-keys.issue', $order->public_id))->assertForbidden();

        $this->assertNull($key->fresh()->revoked_at);
    }
}
