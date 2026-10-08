<?php

namespace Tests\Feature\Admin\Catalogue;

use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\Commerce\Catalogue\CatalogueException;
use App\Support\Commerce\Catalogue\StockAdjuster;
use App\Support\Commerce\InventoryReserver;
use App\Support\Commerce\Money;
use App\Support\Commerce\OrderLifecycle;
use App\Support\Commerce\OrderRefunder;
use App\Support\Commerce\RefundRequest;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithCommerce;
use Tests\TestCase;

/**
 * Rules the catalogue screens must never break: stock history that orders rely on, shortages that are
 * real ones, and a default variant that is always there.
 */
class CatalogueInvariantsTest extends TestCase
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

    // --- Stock history that orders rely on --------------------------------------------------------

    #[Test]
    public function stock_that_an_order_holds_cannot_stop_being_tracked(): void
    {
        [$order] = $this->placeOrder(quantity: 2, stock: 10);
        $item = InventoryItem::sole();
        $this->assertSame(8, $item->quantity);

        $this->actingAs($this->admin())->delete(route('acp.commerce.stock.destroy', $item))
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'Orders have used this stock'));

        // The reservation is still there, so cancelling the order still gives back exactly what it took.
        $this->assertSame(1, InventoryItem::count());
        $this->assertSame(1, InventoryMovement::where('order_id', $order->id)->count());
        app(InventoryReserver::class)->release($order->fresh());
        $this->assertSame(10, $item->fresh()->quantity);
    }

    #[Test]
    public function the_history_is_kept_after_the_order_is_over_too(): void
    {
        [$order] = $this->placeOrder(quantity: 1, stock: 4);
        app(OrderLifecycle::class)->cancel($order);

        $this->actingAs($this->admin())->delete(route('acp.commerce.stock.destroy', InventoryItem::sole()))
            ->assertSessionHas('error');

        $this->assertSame(2, InventoryMovement::count(), 'the reservation and the release are both still on record');
    }

    #[Test]
    public function stock_a_refund_may_return_keeps_its_reservation(): void
    {
        [$order] = $this->placePaidOrder(price: '10.00', quantity: 3, stock: 10);
        $item = InventoryItem::sole();

        $this->actingAs($this->admin())->delete(route('acp.commerce.stock.destroy', $item))->assertSessionHas('error');

        // A full refund that returns the stock still finds the three it is to give back.
        app(OrderRefunder::class)->request($order, Money::parse('30.00', 'USD'), new RefundRequest(token: (string) Str::uuid(), restock: true));

        $this->assertSame(10, $item->fresh()->quantity);
    }

    #[Test]
    public function stock_that_orders_never_touched_can_stop_being_tracked(): void
    {
        $product = Product::factory()->stocked(3)->create();
        $item = InventoryItem::sole();
        $this->actingAs($this->admin())->put(route('acp.commerce.stock.update', $item), ['mode' => 'add', 'quantity' => 2]);

        $this->delete(route('acp.commerce.stock.destroy', $item))->assertSessionHas('success');

        $this->assertSame(0, InventoryItem::count());
        $this->assertNotNull($product->fresh());
    }

    #[Test]
    public function the_service_refuses_too_and_changes_nothing(): void
    {
        $this->placeOrder(quantity: 1, stock: 4);
        $item = InventoryItem::sole();

        try {
            app(StockAdjuster::class)->untrack($item);
            $this->fail('Stock that orders have used was untracked.');
        } catch (CatalogueException $exception) {
            $this->assertStringContainsString('history is kept', $exception->getMessage());
        }

        $this->assertSame(3, $item->fresh()->quantity);
        $this->assertTrue(app(StockAdjuster::class)->usedByOrders($item));
    }

    #[Test]
    public function the_page_says_whether_stock_can_stop_being_tracked(): void
    {
        [, , $product] = $this->placeOrder(quantity: 1, stock: 4);
        $other = Product::factory()->stocked(3)->create();

        $admin = $this->admin();
        $this->actingAs($admin)->get(route('acp.commerce.products.edit', $product))
            ->assertInertia(fn (Assert $page) => $page->where('stock.can_untrack', false));
        $this->get(route('acp.commerce.products.edit', $other))
            ->assertInertia(fn (Assert $page) => $page->where('stock.can_untrack', true));
    }

    // --- Shortages are real ones ------------------------------------------------------------------

    #[Test]
    public function stock_of_archived_products_and_switched_off_variants_is_not_a_shortage(): void
    {
        Product::factory()->inactive()->stocked(0)->create(['name' => 'Archived']);
        $product = Product::factory()->create(['name' => 'Tee']);
        ProductVariant::factory()->for($product)->stocked(0)->create(['name' => 'Off', 'is_active' => false]);
        ProductVariant::factory()->for($product)->stocked(2)->create(['name' => 'On']);

        $this->actingAs($this->admin())->get(route('acp.commerce.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('lowStock', 1)
                ->where('lowStock.0.variant', 'On')
                ->where('metrics.inventory.items', 1)
                ->where('metrics.inventory.out_of_stock', 0)
                ->where('metrics.inventory.on_hand', 2));
    }

    #[Test]
    public function unavailable_stock_cannot_crowd_real_shortages_out_of_the_list(): void
    {
        // Twelve archived products that are deeply negative would fill the ten slots and, because the
        // list is worst-first, hide the one product on sale that is nearly out.
        foreach (range(1, 12) as $n) {
            $archived = Product::factory()->inactive()->create(['name' => "Archived {$n}"]);
            InventoryItem::factory()->create(['product_id' => $archived->id, 'quantity' => -100 * $n, 'allow_backorder' => false]);
        }
        Product::factory()->stocked(1)->create(['name' => 'Really low']);

        $this->actingAs($this->admin())->get(route('acp.commerce.index'))
            ->assertInertia(fn (Assert $page) => $page->has('lowStock', 1)->where('lowStock.0.product', 'Really low'));
    }

    #[Test]
    public function a_product_list_ignores_the_stock_of_variants_that_are_off(): void
    {
        $product = Product::factory()->priced('10.00')->create(['name' => 'Tee']);
        ProductVariant::factory()->for($product)->stocked(0)->create(['is_active' => false]);
        ProductVariant::factory()->for($product)->stocked(40)->create();

        $this->actingAs($this->admin())->get(route('acp.commerce.products.index'))
            ->assertInertia(fn (Assert $page) => $page->where('products.data.0.stock', ['tracked' => true, 'total' => 40, 'status' => 'in_stock']));
    }

    // --- A product with variants keeps a default --------------------------------------------------

    private function productWithTwoVariants(): array
    {
        $product = Product::factory()->create();
        $first = ProductVariant::factory()->for($product)->create(['name' => 'First', 'is_default' => true]);
        $second = ProductVariant::factory()->for($product)->create(['name' => 'Second']);

        return [$product, $first, $second];
    }

    #[Test]
    public function the_current_default_cannot_be_cleared(): void
    {
        [, $first, $second] = $this->productWithTwoVariants();

        $this->actingAs($this->admin())->put(route('acp.commerce.variants.update', $first), ['name' => 'First', 'is_default' => false])
            ->assertSessionHasErrors(['is_default' => 'A product needs a default variant. Make another variant the default instead.']);

        $this->assertTrue($first->fresh()->is_default);
        $this->assertFalse($second->fresh()->is_default);
    }

    #[Test]
    public function the_default_is_changed_by_choosing_another(): void
    {
        [$product, $first, $second] = $this->productWithTwoVariants();

        $this->actingAs($this->admin())->put(route('acp.commerce.variants.update', $second), ['name' => 'Second', 'is_default' => true])
            ->assertSessionHasNoErrors();

        $this->assertTrue($second->fresh()->is_default);
        $this->assertFalse($first->fresh()->is_default);
        $this->assertSame(1, $product->variants()->where('is_default', true)->count());

        // And the old default may now be edited freely.
        $this->put(route('acp.commerce.variants.update', $first), ['name' => 'Renamed', 'is_default' => false])->assertSessionHasNoErrors();
        $this->assertSame('Renamed', $first->fresh()->name);
    }

    #[Test]
    public function editing_the_default_without_touching_the_switch_keeps_it(): void
    {
        [, $first] = $this->productWithTwoVariants();
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('acp.commerce.variants.update', $first), ['name' => 'Renamed'])->assertSessionHasNoErrors();
        $this->put(route('acp.commerce.variants.update', $first), ['name' => 'Renamed again', 'is_default' => true])->assertSessionHasNoErrors();

        $this->assertTrue($first->fresh()->is_default);
    }

    #[Test]
    public function a_default_variant_may_be_switched_off_but_stays_the_default(): void
    {
        [$product, $first] = $this->productWithTwoVariants();

        $this->actingAs($this->admin())->put(route('acp.commerce.variants.update', $first), ['name' => 'First', 'is_default' => true, 'is_active' => false])
            ->assertSessionHasNoErrors();

        $this->assertFalse($first->fresh()->is_active);
        $this->assertTrue($first->fresh()->is_default);
        $this->assertSame(1, $product->variants()->where('is_default', true)->count());
    }

    #[Test]
    public function a_product_with_variants_always_has_exactly_one_default_through_every_path(): void
    {
        $product = Product::factory()->create();
        $admin = $this->admin();
        $this->actingAs($admin);

        // Added with the switch off, the first variant is still the default.
        $this->post(route('acp.commerce.variants.store', $product), ['name' => 'A', 'is_default' => false])->assertSessionHasNoErrors();
        $this->assertSame(1, $product->variants()->where('is_default', true)->count());

        $this->post(route('acp.commerce.variants.store', $product), ['name' => 'B'])->assertSessionHasNoErrors();
        $this->assertSame(1, $product->variants()->where('is_default', true)->count());

        // Deleting the default promotes another.
        $this->delete(route('acp.commerce.variants.destroy', $product->variants()->where('is_default', true)->first()))->assertSessionHas('success');
        $this->assertSame(1, $product->variants()->where('is_default', true)->count());
    }
}
