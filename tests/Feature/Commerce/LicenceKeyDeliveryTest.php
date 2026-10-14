<?php

namespace Tests\Feature\Commerce;

use App\Enums\OrderStatus;
use App\Models\DownloadGrant;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\LicenceKey;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductFile;
use App\Models\ProductVariant;
use App\Models\User;
use App\Notifications\OrderConfirmation;
use App\Support\Commerce\CartManager;
use App\Support\Commerce\Digital\LicenceKeys;
use App\Support\Commerce\OrderLifecycle;
use App\Support\Commerce\PendingOrderExpirer;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithCommerce;
use Tests\Support\SellsLicenceKeys;
use Tests\TestCase;

/**
 * Selling licence keys: a unit is held when the order is placed, the key is handed over when it is paid,
 * and the stock count follows the pool the whole way, so no key is ever sold twice or promised and missing.
 */
class LicenceKeyDeliveryTest extends TestCase
{
    use InteractsWithCommerce;
    use RefreshDatabase;
    use SellsLicenceKeys;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->setUpCommerce();
    }

    // --- Holding and handing over ------------------------------------------------------------------

    #[Test]
    public function an_order_holds_a_unit_and_is_given_a_key_when_it_is_paid(): void
    {
        $product = $this->keyedProduct(3);
        $stock = $this->stockOf($product);
        $this->assertSame(3, $stock->quantity);

        $order = $this->buy($product, pay: false);

        $this->assertSame(2, $stock->fresh()->quantity, 'the unit is held while the order waits for payment');
        $this->assertTrue($order->items->sole()->delivers_keys, 'the line remembers it was sold as a licence key');
        $this->assertSame([], $this->keysOf($order), 'nothing is handed over before it is paid');
        $this->assertStockFollowsPool($product);

        $this->deliverStripeEvent($this->stripeEvent('checkout.session.completed', $this->sessionFor($order->payments()->sole())))->assertOk();

        $order->refresh();
        $this->assertSame(["KEY-1-{$product->id}"], $this->keysOf($order));
        $this->assertSame(OrderStatus::Completed, $order->status, 'nothing is shipped, so the key is the whole delivery');
        $this->assertSame(2, $stock->fresh()->quantity, 'handing the key over does not touch the count: the unit was already held');
        $this->assertStockFollowsPool($product);
        $this->assertNotNull(LicenceKey::query()->whereNotNull('order_item_id')->sole()->issued_at);
    }

    #[Test]
    public function each_unit_gets_its_own_key_and_two_orders_never_share_one(): void
    {
        $product = $this->keyedProduct(5);

        $first = $this->buy($product, 2);
        $second = $this->buy($product, 2);

        $keys = [...$this->keysOf($first), ...$this->keysOf($second)];

        $this->assertCount(4, $keys);
        $this->assertCount(4, array_unique($keys), 'no key is sold twice');
        $this->assertSame(1, LicenceKey::query()->unused()->count());
        $this->assertSame(1, $this->stockOf($product)->quantity);
        $this->assertStockFollowsPool($product);
    }

    #[Test]
    public function a_redelivered_webhook_issues_nothing_more(): void
    {
        $product = $this->keyedProduct(3);
        $order = $this->buy($product, pay: false);
        $event = $this->stripeEvent('checkout.session.completed', $this->sessionFor($order->payments()->sole()));

        $this->deliverStripeEvent($event)->assertOk();
        $this->deliverStripeEvent($event)->assertOk();
        // A second event for the same payment, as Stripe sometimes sends.
        $this->deliverStripeEvent($this->stripeEvent('checkout.session.async_payment_succeeded', $this->sessionFor($order->payments()->sole())))->assertOk();

        $this->assertCount(1, $this->keysOf($order));
        $this->assertSame(2, LicenceKey::query()->unused()->count());
        $this->assertStockFollowsPool($product);
    }

    #[Test]
    public function the_last_key_cannot_be_bought_twice(): void
    {
        $product = $this->keyedProduct(1);

        $this->buy($product, pay: false);
        $this->assertSame(0, $this->stockOf($product)->quantity);

        // Someone else wants it too: it is spoken for.
        $this->cartWith($product);
        $this->post(route('shop.checkout.store'), $this->checkoutPayload())->assertRedirect(route('shop.cart'))->assertSessionHas('error');

        $this->assertSame(1, Order::query()->count(), 'no second order was placed');
        $this->assertStockFollowsPool($product);
    }

    #[Test]
    public function an_order_that_expires_gives_its_unit_back_and_no_key_was_ever_taken(): void
    {
        $product = $this->keyedProduct(3);
        $this->buy($product, 2, pay: false);
        $this->assertSame(1, $this->stockOf($product)->quantity);

        $this->travel(61)->minutes();
        app(PendingOrderExpirer::class)->run();

        $this->assertSame(3, $this->stockOf($product)->quantity);
        $this->assertSame(3, LicenceKey::query()->unused()->count(), 'the pool was never touched');
        $this->assertStockFollowsPool($product);
    }

    #[Test]
    public function keys_come_from_the_pool_of_the_variant_that_was_bought(): void
    {
        $product = Product::factory()->digital()->create();
        $standard = ProductVariant::factory()->for($product)->priced('10.00')->keyed(2)->create(['name' => 'Standard']);
        $pro = ProductVariant::factory()->for($product)->priced('30.00')->keyed(2)->create(['name' => 'Pro']);

        $order = $this->buy($pro);

        $this->assertSame(["KEY-1-v{$pro->id}"], $this->keysOf($order));
        $this->assertSame(2, LicenceKey::query()->where('inventory_item_id', $this->stockOf($standard)->id)->unused()->count(), 'the other variant is untouched');
        $this->assertSame(1, $this->stockOf($pro)->quantity);
        $this->assertStockFollowsPool($pro);
        $this->assertStockFollowsPool($standard);
    }

    // --- What the customer sees --------------------------------------------------------------------

    #[Test]
    public function the_customer_sees_the_key_on_their_order_page_once_it_is_paid(): void
    {
        $product = $this->keyedProduct(2, name: 'Pro licence');
        $pending = $this->buy($product, pay: false);

        $this->orderPage($pending)->assertInertia(fn (Assert $page) => $page
            ->where('licenceKeys', [])
            ->where('licenceKeysPending', true));

        $this->deliverStripeEvent($this->stripeEvent('checkout.session.completed', $this->sessionFor($pending->payments()->sole())))->assertOk();

        $this->orderPage($pending)->assertInertia(fn (Assert $page) => $page
            ->where('licenceKeysPending', false)
            ->where('licenceKeys.0.description', 'Pro licence')
            ->where('licenceKeys.0.quantity', 1)
            ->where('licenceKeys.0.keys', ["KEY-1-{$product->id}"])
            ->where('licenceKeys.0.waiting', 0));
    }

    #[Test]
    public function the_order_page_is_never_cached_and_its_history_is_encrypted(): void
    {
        $product = $this->keyedProduct(1);
        $order = $this->buy($product);

        $response = $this->orderPage($order)->assertOk();

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertTrue($response->viewData('page')['encryptHistory'], 'the keys are not kept in the browser history in the clear');
    }

    #[Test]
    public function an_order_without_keys_is_left_as_it_was(): void
    {
        $plain = Product::factory()->priced('15.00')->stocked(3)->create();
        $order = $this->buy($plain);

        $response = $this->orderPage($order)->assertOk();

        $this->assertFalse($response->viewData('page')['encryptHistory'] ?? false, 'only a page that shows keys is kept out of the browser history');
        $response->assertInertia(fn (Assert $page) => $page->where('licenceKeys', [])->where('licenceKeysPending', false));
    }

    #[Test]
    public function only_the_buyer_can_open_the_order_page_and_so_see_the_key(): void
    {
        $product = $this->keyedProduct(1);
        $order = $this->buy($product);

        // No signed link and not the owner.
        $this->get(route('shop.checkout.complete', ['order' => $order->public_id]))->assertForbidden();
        $this->actingAs(User::factory()->create())->get(route('shop.checkout.complete', ['order' => $order->public_id]))->assertForbidden();
    }

    #[Test]
    public function the_confirmation_email_points_to_the_order_page_and_never_carries_the_key(): void
    {
        $product = $this->keyedProduct(1);
        $order = $this->buy($product);

        $mail = (new OrderConfirmation($order))->toMail(new AnonymousNotifiable);
        $text = implode("\n", [...$mail->introLines, ...$mail->outroLines]);

        $this->assertStringContainsString('licence keys are on your order page', $text);
        $this->assertStringNotContainsString("KEY-1-{$product->id}", $text);
    }

    // --- Orders that are not just keys -------------------------------------------------------------

    #[Test]
    public function an_order_that_also_has_something_to_ship_is_not_completed_by_its_key(): void
    {
        $licence = $this->keyedProduct(2);
        $mug = Product::factory()->priced('9.00')->stocked(5)->create();

        $cart = $this->cartWith($licence);
        CartManager::addItem($cart, $mug, null, $mug->prices()->firstOrFail(), 1);
        $this->post(route('shop.checkout.store'), $this->checkoutPayload())->assertRedirect();
        $order = Order::query()->latest('id')->firstOrFail();
        $this->deliverStripeEvent($this->stripeEvent('checkout.session.completed', $this->sessionFor($order->payments()->sole())))->assertOk();

        $order->refresh();
        $this->assertCount(1, $this->keysOf($order), 'the key is issued');
        $this->assertSame(OrderStatus::Processing, $order->status, 'the mug still has to be shipped');

        $this->assertTrue(app(OrderLifecycle::class)->fulfil($order));
        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
    }

    #[Test]
    public function a_product_with_files_and_keys_needs_both_to_be_delivered(): void
    {
        $product = Product::factory()->priced('20.00')->digital()->keyed(1)->create();
        ProductFile::factory()->for($product)->withContents('installer')->create();

        $order = $this->buy($product);

        $this->assertCount(1, $this->keysOf($order));
        $this->assertNotNull(DownloadGrant::query()->where('order_id', $order->id)->first(), 'the files are granted too');
        $this->assertSame(OrderStatus::Completed, $order->status);
    }

    #[Test]
    public function a_line_bought_before_keys_were_switched_on_is_owed_nothing(): void
    {
        $product = Product::factory()->priced('15.00')->digital()->stocked(5)->create();
        $early = $this->buy($product);
        $this->assertFalse($early->items->sole()->delivers_keys);

        // Later the product starts delivering keys.
        $stock = app(LicenceKeys::class)->enable($product, null);
        app(LicenceKeys::class)->add($stock, "ONE\nTWO");

        $this->assertSame([], $this->keysOf($early), 'the earlier order is not given a key');
        $this->assertSame(0, app(OrderLifecycle::class)->issueWaitingKeys($stock));
        $this->assertSame(2, LicenceKey::query()->unused()->count());
        $this->assertStockFollowsPool($product);
    }

    #[Test]
    public function an_order_that_costs_nothing_is_given_its_key_on_the_spot(): void
    {
        $product = $this->keyedProduct(2, price: '0.00');

        $this->cartWith($product);
        $this->post(route('shop.checkout.store'), $this->checkoutPayload())->assertRedirect();
        $order = Order::query()->latest('id')->firstOrFail()->refresh();

        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertCount(1, $this->keysOf($order));
        $this->assertStockFollowsPool($product);
    }

    // --- Paid, but the pool had nothing to give ----------------------------------------------------

    #[Test]
    public function a_paid_order_the_pool_cannot_supply_waits_and_says_so(): void
    {
        [$product, $late] = $this->lateOrderWithNoKeyLeft();

        $this->assertSame(OrderStatus::Processing, $late->status, 'paid, so honoured, but not complete');
        $this->assertSame([], $this->keysOf($late));
        $this->assertSame(-1, $this->stockOf($product)->quantity, 'the unit it is owed is spoken for, like any late payment');
        $this->assertStockFollowsPool($product);

        $this->orderPage($late)->assertInertia(fn (Assert $page) => $page
            ->where('licenceKeys.0.keys', [])
            ->where('licenceKeys.0.waiting', 1));
    }

    #[Test]
    public function keys_added_later_go_to_the_orders_that_were_waiting_oldest_first(): void
    {
        [$product, $late] = $this->lateOrderWithNoKeyLeft();
        $stock = $this->stockOf($product);
        $admin = User::factory()->create();
        $this->seed(RolePermissionSeeder::class);
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->post(route('acp.commerce.licence-keys.store', $stock->id), ['keys' => "LATE-1\nLATE-2"])
            ->assertSessionHas('success');

        $late->refresh();
        $this->assertSame(['LATE-1'], $this->keysOf($late), 'the waiting order gets the first key');
        $this->assertSame(OrderStatus::Completed, $late->status, 'and is complete now');
        $this->assertSame(1, LicenceKey::query()->unused()->count(), 'the second key stays in the pool');
        $this->assertSame(1, $stock->fresh()->quantity);
        $this->assertStockFollowsPool($product);
        $this->assertTrue($late->events()->where('type', 'licence_issued')->exists());
    }

    #[Test]
    public function staff_can_issue_the_keys_a_paid_order_is_still_owed(): void
    {
        [$product, $late] = $this->lateOrderWithNoKeyLeft();
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create()->assignRole('admin');

        // Nothing in the pool yet.
        $this->actingAs($admin)->post(route('acp.commerce.orders.licence-keys.issue', $late->public_id))->assertSessionHas('error');
        $this->assertSame([], $this->keysOf($late));

        // A key appears in the pool without the order screen hearing about it (a direct import).
        LicenceKey::factory()->create(['inventory_item_id' => $this->stockOf($product)->id, 'value' => 'DIRECT-1']);

        $this->actingAs($admin)->post(route('acp.commerce.orders.licence-keys.issue', $late->public_id))->assertSessionHas('success');

        $this->assertSame(['DIRECT-1'], $this->keysOf($late));
        $this->assertSame(OrderStatus::Completed, $late->fresh()->status);
    }

    #[Test]
    public function the_history_of_an_order_says_a_key_was_issued_but_never_what_it_was(): void
    {
        $product = $this->keyedProduct(1);
        $order = $this->buy($product);

        $event = $order->events()->where('type', 'licence_issued')->sole();

        $this->assertStringContainsString('Issued 1 licence key', (string) $event->message);
        $this->assertStringNotContainsString("KEY-1-{$product->id}", json_encode($event->toArray()));
    }

    // --- An order keeps the stock it reserved from ------------------------------------------------

    /**
     * Keys are kept on the product's own stock, and a variant with no stock of its own is sold from it.
     *
     * @return array{0: Product, 1: ProductVariant}
     */
    private function variantSoldFromTheProductsPool(int $keys): array
    {
        $product = Product::factory()->digital()->keyed($keys)->create();
        $variant = ProductVariant::factory()->for($product)->priced('12.00')->create(['name' => 'Team']);

        return [$product, $variant];
    }

    private function payFor(Order $order): void
    {
        $this->deliverStripeEvent($this->stripeEvent('checkout.session.completed', $this->sessionFor($order->payments()->sole())))->assertOk();
    }

    #[Test]
    public function a_line_is_given_its_key_from_the_stock_it_reserved_from_even_if_the_variant_is_given_stock_before_payment(): void
    {
        [$product, $variant] = $this->variantSoldFromTheProductsPool(2);
        $pool = $this->stockOf($product);
        $order = $this->buy($variant, pay: false);
        $this->assertSame(1, $pool->fresh()->quantity, 'the unit was held on the product\'s pool');

        // After checkout, staff give the variant a stock record of its own: an ordinary one.
        $own = InventoryItem::factory()->create(['product_id' => $product->id, 'product_variant_id' => $variant->id, 'quantity' => 5]);

        $this->payFor($order);

        $order->refresh();
        $this->assertSame(["KEY-1-{$product->id}"], $this->keysOf($order), 'the key comes from the pool that held the unit');
        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertSame(5, $own->fresh()->quantity, 'the new ordinary stock was never touched');
        $this->assertSame(1, $pool->fresh()->quantity);
        $this->assertSame(1, LicenceKey::query()->unused()->count());
        $this->assertStockFollowsPool($product);
    }

    #[Test]
    public function a_late_payment_takes_its_unit_back_from_the_same_stock_it_first_held(): void
    {
        [$product, $variant] = $this->variantSoldFromTheProductsPool(1);
        $pool = $this->stockOf($product);
        $order = $this->buy($variant, pay: false);
        $this->deliverStripeEvent($this->stripeEvent('checkout.session.expired', $this->sessionFor($order->payments()->sole(), paid: false)))->assertOk();
        $this->assertSame(1, $pool->fresh()->quantity, 'the unit went back');

        $own = InventoryItem::factory()->create(['product_id' => $product->id, 'product_variant_id' => $variant->id, 'quantity' => 5]);

        $this->payFor($order);

        $order->refresh();
        $this->assertSame(0, $pool->fresh()->quantity, 'the order took its unit from the pool again');
        $this->assertSame(5, $own->fresh()->quantity, 'and not from the stock that was added meanwhile');
        $this->assertSame(["KEY-1-{$product->id}"], $this->keysOf($order));
        $this->assertStockFollowsPool($product);
    }

    #[Test]
    public function keys_added_later_still_reach_an_order_owed_from_the_stock_it_reserved_from(): void
    {
        [$product, $variant] = $this->variantSoldFromTheProductsPool(1);
        $pool = $this->stockOf($product);
        $other = ProductVariant::factory()->for($product)->priced('9.00')->create(['name' => 'Solo']);

        // The Team order's checkout expires; somebody buys the only key (as another variant of the product).
        $late = $this->buy($variant, pay: false);
        $this->deliverStripeEvent($this->stripeEvent('checkout.session.expired', $this->sessionFor($late->payments()->sole(), paid: false)))->assertOk();
        $this->buy($other);

        // The Team variant is then given stock of its own, and the Team order's payment arrives after all.
        InventoryItem::factory()->create(['product_id' => $product->id, 'product_variant_id' => $variant->id, 'quantity' => 5]);
        $this->payFor($late);
        $this->assertSame([], $this->keysOf($late->refresh()), 'no key was left to give');
        $this->assertSame(-1, $pool->fresh()->quantity);

        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create()->assignRole('admin');
        $this->actingAs($admin)->post(route('acp.commerce.licence-keys.store', $pool->id), ['keys' => 'LATER-1'])->assertSessionHas('success');

        $this->assertSame(['LATER-1'], $this->keysOf($late->refresh()), 'the waiting order is found through the stock it reserved from');
        $this->assertSame(OrderStatus::Completed, $late->status);
        $this->assertStockFollowsPool($product);
    }

    #[Test]
    public function the_movement_ledger_explains_the_count(): void
    {
        $product = $this->keyedProduct(2);
        $order = $this->buy($product);

        $reasons = InventoryMovement::query()->where('inventory_item_id', $this->stockOf($product)->id)->orderBy('id')->pluck('reason')->all();

        $this->assertSame(['adjustment', 'reservation'], $reasons, 'keys added, then a unit held for the order');
        $this->assertSame($order->id, InventoryMovement::query()->where('reason', 'reservation')->sole()->order_id);
    }
}
