<?php

namespace Tests\Feature\Admin\Catalogue;

use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\Commerce\Catalogue\StockAdjuster;
use App\Support\Commerce\InventoryReserver;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithCommerce;
use Tests\TestCase;

class ProductStockTest extends TestCase
{
    use InteractsWithCommerce;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->setUpCommerce();
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('admin');
    }

    // --- Tracking ---------------------------------------------------------------------------------

    #[Test]
    public function stock_is_tracked_for_a_product_with_an_opening_count_in_the_ledger(): void
    {
        $product = Product::factory()->create();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('acp.commerce.stock.track', $product), ['quantity' => 40, 'allow_backorder' => false])
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $item = InventoryItem::sole();
        $this->assertSame([$product->id, null, 40, false], [$item->product_id, $item->product_variant_id, $item->quantity, $item->allow_backorder]);

        $movement = InventoryMovement::sole();
        $this->assertSame([$item->id, 40, 'adjustment', 'Opening stock', $admin->id, null], [
            $movement->inventory_item_id, $movement->delta, $movement->reason, $movement->note, $movement->user_id, $movement->order_id,
        ]);
    }

    #[Test]
    public function stock_is_tracked_for_a_variant(): void
    {
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->for($product)->create();

        $this->actingAs($this->admin())->post(route('acp.commerce.stock.track', $product), ['product_variant_id' => $variant->id, 'quantity' => 0, 'allow_backorder' => true])
            ->assertSessionHasNoErrors();

        $item = InventoryItem::sole();
        $this->assertSame($variant->id, $item->product_variant_id);
        $this->assertTrue($item->allow_backorder);
        $this->assertSame(0, InventoryMovement::count(), 'an opening count of nothing is not a movement');
    }

    #[Test]
    public function an_item_cannot_be_tracked_twice(): void
    {
        $product = Product::factory()->stocked(5)->create();
        $variant = ProductVariant::factory()->for($product)->stocked(5)->create();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('acp.commerce.stock.track', $product), ['quantity' => 9])
            ->assertSessionHas('errors', fn ($bag) => str_contains($bag->first('quantity'), 'already tracked'));
        $this->post(route('acp.commerce.stock.track', $product), ['product_variant_id' => $variant->id, 'quantity' => 9])
            ->assertSessionHas('errors', fn ($bag) => str_contains($bag->first('quantity'), 'already tracked'));

        $this->assertSame(2, InventoryItem::count());
        $this->assertSame(5, InventoryItem::where('product_id', $product->id)->whereNull('product_variant_id')->sole()->quantity);
    }

    #[Test]
    public function only_the_products_own_variants_can_be_tracked_through_it(): void
    {
        $product = Product::factory()->create();
        $foreign = ProductVariant::factory()->create();

        $this->actingAs($this->admin())->post(route('acp.commerce.stock.track', $product), ['product_variant_id' => $foreign->id, 'quantity' => 5])
            ->assertSessionHasErrors('product_variant_id');

        $this->assertSame(0, InventoryItem::count());
    }

    #[Test]
    public function an_opening_count_is_a_whole_number_within_range(): void
    {
        $product = Product::factory()->create();
        $admin = $this->admin();

        foreach (['', 'x', '-1', '1.5', (string) (StockAdjuster::LIMIT + 1)] as $quantity) {
            $this->actingAs($admin)->post(route('acp.commerce.stock.track', $product), ['quantity' => $quantity])->assertSessionHasErrors('quantity');
        }

        $this->assertSame(0, InventoryItem::count());
    }

    // --- Adjusting --------------------------------------------------------------------------------

    #[Test]
    public function the_count_is_set_to_a_figure_and_the_difference_is_recorded(): void
    {
        Product::factory()->stocked(10)->create();
        $item = InventoryItem::sole();
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('acp.commerce.stock.update', $item), ['mode' => 'set', 'quantity' => 7, 'note' => 'Stock take'])
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame(7, $item->fresh()->quantity);
        $movement = InventoryMovement::sole();
        $this->assertSame([-3, 'adjustment', 'Stock take', $admin->id], [$movement->delta, $movement->reason, $movement->note, $movement->user_id]);
    }

    #[Test]
    public function stock_is_added_and_removed(): void
    {
        Product::factory()->stocked(10)->create();
        $item = InventoryItem::sole();
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('acp.commerce.stock.update', $item), ['mode' => 'add', 'quantity' => 25, 'note' => 'Delivery'])->assertSessionHasNoErrors();
        $this->put(route('acp.commerce.stock.update', $item), ['mode' => 'add', 'quantity' => -4, 'note' => 'Damaged'])->assertSessionHasNoErrors();

        $this->assertSame(31, $item->fresh()->quantity);
        $this->assertSame([25, -4], InventoryMovement::orderBy('id')->pluck('delta')->all());
    }

    #[Test]
    public function setting_the_count_it_already_has_leaves_no_movement(): void
    {
        Product::factory()->stocked(10)->create();

        $this->actingAs($this->admin())->put(route('acp.commerce.stock.update', InventoryItem::sole()), ['mode' => 'set', 'quantity' => 10])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, InventoryMovement::count());
    }

    #[Test]
    public function stock_cannot_be_taken_below_zero_unless_it_may_be_backordered(): void
    {
        Product::factory()->stocked(3)->create();
        $item = InventoryItem::sole();
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('acp.commerce.stock.update', $item), ['mode' => 'add', 'quantity' => -4])
            ->assertSessionHas('errors', fn ($bag) => str_contains($bag->first('quantity'), 'only 3 available'));
        $this->put(route('acp.commerce.stock.update', $item), ['mode' => 'set', 'quantity' => -1])
            ->assertSessionHas('errors', fn ($bag) => str_contains($bag->first('quantity'), 'below zero'));

        $this->assertSame(3, $item->fresh()->quantity);
        $this->assertSame(0, InventoryMovement::count());

        $item->update(['allow_backorder' => true]);
        $this->put(route('acp.commerce.stock.update', $item), ['mode' => 'add', 'quantity' => -4])->assertSessionHasNoErrors();
        $this->assertSame(-1, $item->fresh()->quantity, 'owed to customers who ordered ahead');
    }

    #[Test]
    public function an_adjustment_is_validated(): void
    {
        Product::factory()->stocked(3)->create();
        $item = InventoryItem::sole();
        $admin = $this->admin();
        $limit = StockAdjuster::LIMIT;

        $this->actingAs($admin)->put(route('acp.commerce.stock.update', $item), ['mode' => 'set'])->assertSessionHasErrors('quantity');
        $this->put(route('acp.commerce.stock.update', $item), ['quantity' => 5])->assertSessionHasErrors('mode');
        $this->put(route('acp.commerce.stock.update', $item), ['mode' => 'multiply', 'quantity' => 5])->assertSessionHasErrors('mode');
        $this->put(route('acp.commerce.stock.update', $item), ['mode' => 'add', 'quantity' => 1.5])->assertSessionHasErrors('quantity');
        $this->put(route('acp.commerce.stock.update', $item), ['mode' => 'add', 'quantity' => $limit + 1])->assertSessionHasErrors('quantity');
        $this->put(route('acp.commerce.stock.update', $item), ['mode' => 'add', 'quantity' => 1, 'note' => str_repeat('a', 256)])->assertSessionHasErrors('note');

        $this->assertSame(3, $item->fresh()->quantity);
    }

    #[Test]
    public function backorder_can_be_switched_without_touching_the_count(): void
    {
        Product::factory()->stocked(3)->create();
        $item = InventoryItem::sole();

        $this->actingAs($this->admin())->put(route('acp.commerce.stock.update', $item), ['allow_backorder' => true])->assertSessionHasNoErrors();

        $this->assertTrue($item->fresh()->allow_backorder);
        $this->assertSame(3, $item->fresh()->quantity);
        $this->assertSame(0, InventoryMovement::count());
    }

    #[Test]
    public function a_stock_figure_set_after_a_sale_counts_what_is_still_for_sale(): void
    {
        [$order] = $this->placeOrder(quantity: 2, stock: 10); // 8 left for sale; 2 are spoken for.
        $item = InventoryItem::sole();
        $this->assertSame(8, $item->quantity);

        $this->actingAs($this->admin())->put(route('acp.commerce.stock.update', $item), ['mode' => 'add', 'quantity' => 5, 'note' => 'Delivery'])->assertSessionHasNoErrors();

        // The count and the order's reservation stay separate entries in one ledger.
        $this->assertSame(13, $item->fresh()->quantity);
        $this->assertSame([-2, 5], InventoryMovement::orderBy('id')->pluck('delta')->all());
        $this->assertSame($order->id, InventoryMovement::orderBy('id')->first()->order_id);

        // And cancelling the order still gives back exactly what it took.
        app(InventoryReserver::class)->release($order->fresh());
        $this->assertSame(15, $item->fresh()->quantity);
    }

    // --- Untracking -------------------------------------------------------------------------------

    #[Test]
    public function stock_can_stop_being_tracked(): void
    {
        $product = Product::factory()->stocked(3)->create();
        $item = InventoryItem::sole();
        $this->actingAs($this->admin())->put(route('acp.commerce.stock.update', $item), ['mode' => 'add', 'quantity' => 2]);

        $this->delete(route('acp.commerce.stock.destroy', $item))->assertSessionHas('success');

        $this->assertSame(0, InventoryItem::count());
        $this->assertSame(0, InventoryMovement::count(), 'its history goes with it');
        $this->assertNotNull($product->fresh());
    }

    // --- The page ---------------------------------------------------------------------------------

    #[Test]
    public function the_product_page_shows_each_variants_stock_and_recent_movements(): void
    {
        $product = Product::factory()->create();
        $tracked = ProductVariant::factory()->for($product)->stocked(5)->create(['name' => 'M']);
        ProductVariant::factory()->for($product)->create(['name' => 'L']); // Not tracked.
        $admin = $this->admin();
        $this->actingAs($admin)->put(route('acp.commerce.stock.update', InventoryItem::sole()), ['mode' => 'add', 'quantity' => 3, 'note' => 'Delivery']);

        $this->get(route('acp.commerce.products.edit', $product))->assertInertia(fn (Assert $page) => $page
            ->where('stock', null)
            ->where('variants', function ($variants) use ($tracked, $admin) {
                $m = collect($variants)->firstWhere('id', $tracked->id);
                $l = collect($variants)->firstWhere('name', 'L');

                return $m['stock']['quantity'] === 8
                    && $m['stock']['movements'][0]['delta'] === 3
                    && $m['stock']['movements'][0]['note'] === 'Delivery'
                    && $m['stock']['movements'][0]['by'] === $admin->nickname
                    && $m['stock']['movements'][0]['reason'] === 'adjustment'
                    && $l['stock'] === null;
            }));
    }

    #[Test]
    public function an_order_movement_links_back_to_its_order(): void
    {
        [$order, , $product] = $this->placeOrder(quantity: 1, stock: 4);

        $this->actingAs($this->admin())->get(route('acp.commerce.products.edit', $product))->assertInertia(fn (Assert $page) => $page
            ->where('stock.movements.0.reason', 'reservation')
            ->where('stock.movements.0.delta', -1)
            ->where('stock.movements.0.order', ['number' => $order->number, 'public_id' => $order->public_id]));
    }

    #[Test]
    public function the_history_shown_is_capped_and_newest_first(): void
    {
        $product = Product::factory()->stocked(0)->create();
        $item = InventoryItem::sole();
        $admin = $this->admin();
        $this->actingAs($admin);

        foreach (range(1, 10) as $n) {
            $this->put(route('acp.commerce.stock.update', $item), ['mode' => 'add', 'quantity' => $n]);
        }

        $this->get(route('acp.commerce.products.edit', $product))->assertInertia(fn (Assert $page) => $page
            ->has('stock.movements', 8)
            ->where('stock.movements.0.delta', 10)
            ->where('stock.movements.7.delta', 3));
    }

    #[Test]
    public function stock_changes_need_the_matching_permissions(): void
    {
        $product = Product::factory()->stocked(3)->create();
        $item = InventoryItem::sole();
        $viewer = User::factory()->create()->assignRole('editor');
        $viewer->givePermissionTo(['commerce.acp.view', 'commerce.acp.edit']);

        $this->actingAs($viewer)->put(route('acp.commerce.stock.update', $item), ['mode' => 'add', 'quantity' => 1])->assertSessionHasNoErrors();
        $this->post(route('acp.commerce.stock.track', $product), ['product_variant_id' => null, 'quantity' => 1])->assertForbidden();
        $this->delete(route('acp.commerce.stock.destroy', $item))->assertForbidden();

        $this->assertSame(4, $item->fresh()->quantity);
    }
}
