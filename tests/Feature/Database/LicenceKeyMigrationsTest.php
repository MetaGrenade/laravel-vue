<?php

namespace Tests\Feature\Database;

use App\Models\InventoryItem;
use App\Models\LicenceKey;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The licence key migrations on every database CI supports.
 *
 * DDL commits the test's transaction on MySQL, so a test that changes the schema removes whatever it
 * created before it ends rather than relying on a rollback.
 */
class LicenceKeyMigrationsTest extends TestCase
{
    use RefreshDatabase;

    private function migration(string $file): object
    {
        return require database_path("migrations/{$file}.php");
    }

    #[Test]
    public function the_table_and_the_flags_roll_back_and_forward_cleanly(): void
    {
        $this->assertTrue(Schema::hasColumns('licence_keys', ['inventory_item_id', 'value', 'fingerprint', 'order_item_id', 'issued_at', 'revoked_at', 'revoked_reason', 'added_by']));
        $this->assertTrue(Schema::hasColumn('inventory_items', 'delivers_keys'));
        $this->assertTrue(Schema::hasColumn('order_items', 'delivers_keys'));

        $this->migration('2026_10_16_000100_add_licence_key_flags_to_stock_and_order_items')->down();
        $this->migration('2026_10_16_000000_create_licence_keys_table')->down();

        $this->assertFalse(Schema::hasTable('licence_keys'));
        $this->assertFalse(Schema::hasColumn('inventory_items', 'delivers_keys'));
        $this->assertFalse(Schema::hasColumn('order_items', 'delivers_keys'));

        $this->migration('2026_10_16_000000_create_licence_keys_table')->up();
        $this->migration('2026_10_16_000100_add_licence_key_flags_to_stock_and_order_items')->up();

        $this->assertTrue(Schema::hasTable('licence_keys'));
        $this->assertTrue(Schema::hasColumn('inventory_items', 'delivers_keys'));
        $this->assertTrue(Schema::hasColumn('order_items', 'delivers_keys'));
    }

    #[Test]
    public function stock_and_order_lines_that_existed_before_deliver_no_keys(): void
    {
        $migration = $this->migration('2026_10_16_000100_add_licence_key_flags_to_stock_and_order_items');
        $product = Product::factory()->create();
        $order = Order::factory()->create();
        $stockId = null;
        $itemId = null;

        try {
            $migration->down();
            $stockId = DB::table('inventory_items')->insertGetId(['product_id' => $product->id, 'quantity' => 4, 'allow_backorder' => false, 'created_at' => now(), 'updated_at' => now()]);
            $itemId = DB::table('order_items')->insertGetId(['order_id' => $order->id, 'quantity' => 1, 'unit_price' => 1, 'subtotal' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $migration->up();

            $this->assertFalse((bool) DB::table('inventory_items')->where('id', $stockId)->value('delivers_keys'));
            $this->assertFalse((bool) DB::table('order_items')->where('id', $itemId)->value('delivers_keys'));
        } finally {
            DB::table('order_items')->where('id', $itemId)->delete();
            DB::table('inventory_items')->where('id', $stockId)->delete();
            DB::table('orders')->where('id', $order->id)->delete();
            DB::table('products')->where('id', $product->id)->delete();
        }
    }

    #[Test]
    public function the_same_key_cannot_be_in_one_pool_twice_but_can_be_in_two(): void
    {
        $one = InventoryItem::factory()->keyed()->create();
        $two = InventoryItem::factory()->keyed()->create();
        $key = LicenceKey::fingerprintOf('SAME-KEY');

        LicenceKey::factory()->create(['inventory_item_id' => $one->id, 'value' => 'SAME-KEY', 'fingerprint' => $key]);
        LicenceKey::factory()->create(['inventory_item_id' => $two->id, 'value' => 'SAME-KEY', 'fingerprint' => $key]);

        $this->expectException(QueryException::class);

        // Not inside the test's transaction: a failed statement would poison it on PostgreSQL.
        DB::transaction(fn () => LicenceKey::factory()->create(['inventory_item_id' => $one->id, 'value' => 'SAME-KEY', 'fingerprint' => $key]));
    }

    #[Test]
    public function a_pools_keys_go_with_its_stock_and_an_orders_keys_go_with_its_line(): void
    {
        $stock = InventoryItem::factory()->keyed()->create();
        $order = Order::factory()->paid()->create();
        $item = $order->items()->create(['product_id' => $stock->product_id, 'quantity' => 1, 'unit_price' => '1.00', 'subtotal' => '1.00', 'delivers_keys' => true]);
        $issued = LicenceKey::factory()->create(['inventory_item_id' => $stock->id, 'order_item_id' => $item->id]);
        $unused = LicenceKey::factory()->create(['inventory_item_id' => $stock->id]);

        // A key whose line is deleted goes with it: it must never fall back into the pool and be sold twice.
        $item->delete();
        $this->assertNull(LicenceKey::query()->find($issued->id));
        $this->assertNotNull(LicenceKey::query()->find($unused->id));

        $stock->delete();
        $this->assertSame(0, LicenceKey::query()->count());
    }

    #[Test]
    public function deleting_the_user_who_added_a_key_keeps_the_key(): void
    {
        $user = User::factory()->create();
        $key = LicenceKey::factory()->create(['added_by' => $user->id]);

        $user->delete();

        $this->assertNotNull($key->fresh());
        $this->assertNull($key->fresh()->added_by);
    }
}
