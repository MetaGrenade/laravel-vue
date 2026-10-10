<?php

namespace Tests\Feature\Database;

use App\Models\DownloadCount;
use App\Models\DownloadGrant;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductFile;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The digital delivery migrations on every database CI supports.
 *
 * DDL commits the test's transaction on MySQL, so a test that changes the schema removes whatever it
 * created before it ends rather than relying on a rollback.
 */
class DigitalDeliveryMigrationsTest extends TestCase
{
    use RefreshDatabase;

    private function migration(string $file): object
    {
        return require database_path("migrations/{$file}.php");
    }

    #[Test]
    public function the_tables_and_column_roll_back_and_forward_cleanly(): void
    {
        $this->assertTrue(Schema::hasColumns('product_files', ['product_id', 'name', 'original_name', 'disk', 'path', 'size', 'mime', 'sha256', 'is_active', 'position']));
        $this->assertTrue(Schema::hasColumns('download_grants', ['public_id', 'order_id', 'order_item_id', 'product_id', 'expires_at', 'revoked_at', 'revoked_reason']));
        $this->assertTrue(Schema::hasColumns('download_counts', ['download_grant_id', 'product_file_id', 'downloads', 'last_downloaded_at']));
        $this->assertTrue(Schema::hasColumn('order_items', 'requires_shipping'));

        $this->migration('2026_10_15_000100_add_requires_shipping_to_order_items_table')->down();
        $this->migration('2026_10_15_000000_create_digital_delivery_tables')->down();

        $this->assertFalse(Schema::hasTable('product_files'));
        $this->assertFalse(Schema::hasTable('download_grants'));
        $this->assertFalse(Schema::hasTable('download_counts'));
        $this->assertFalse(Schema::hasColumn('order_items', 'requires_shipping'));

        $this->migration('2026_10_15_000000_create_digital_delivery_tables')->up();
        $this->migration('2026_10_15_000100_add_requires_shipping_to_order_items_table')->up();

        $this->assertTrue(Schema::hasTable('download_counts'));
        $this->assertTrue(Schema::hasColumn('order_items', 'requires_shipping'));
    }

    #[Test]
    public function an_order_line_that_existed_before_is_treated_as_shipped(): void
    {
        $migration = $this->migration('2026_10_15_000100_add_requires_shipping_to_order_items_table');
        $order = Order::factory()->create();
        $itemId = null;

        try {
            $migration->down();
            $itemId = DB::table('order_items')->insertGetId(['order_id' => $order->id, 'quantity' => 1, 'unit_price' => 1, 'subtotal' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $migration->up();

            $this->assertTrue((bool) DB::table('order_items')->where('id', $itemId)->value('requires_shipping'));
        } finally {
            DB::table('order_items')->where('id', $itemId)->delete();
            DB::table('orders')->where('id', $order->id)->delete();
        }
    }

    #[Test]
    public function a_files_rows_go_with_it_and_with_its_product(): void
    {
        $product = Product::factory()->create();
        $file = ProductFile::factory()->for($product)->create();
        $order = Order::factory()->create();
        $item = $order->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => '1.00', 'subtotal' => '1.00']);
        $grant = DownloadGrant::create(['order_id' => $order->id, 'order_item_id' => $item->id, 'product_id' => $product->id]);
        DownloadCount::create(['download_grant_id' => $grant->id, 'product_file_id' => $file->id, 'downloads' => 2]);

        $file->delete();

        $this->assertSame(0, DownloadCount::query()->count(), 'the counts for a deleted file go with it');
        $this->assertModelExists($grant);
    }

    #[Test]
    public function an_order_line_has_one_grant(): void
    {
        $product = Product::factory()->create();
        $order = Order::factory()->create();
        $item = $order->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => '1.00', 'subtotal' => '1.00']);
        DownloadGrant::create(['order_id' => $order->id, 'order_item_id' => $item->id, 'product_id' => $product->id]);

        $this->expectException(QueryException::class);

        DB::table('download_grants')->insert(['public_id' => 'another', 'order_id' => $order->id, 'order_item_id' => $item->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    #[Test]
    public function a_file_has_one_count_for_each_grant(): void
    {
        $product = Product::factory()->create();
        $file = ProductFile::factory()->for($product)->create();
        $order = Order::factory()->create();
        $item = $order->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => '1.00', 'subtotal' => '1.00']);
        $grant = DownloadGrant::create(['order_id' => $order->id, 'order_item_id' => $item->id, 'product_id' => $product->id]);
        DownloadCount::create(['download_grant_id' => $grant->id, 'product_file_id' => $file->id]);

        $this->expectException(QueryException::class);

        DownloadCount::create(['download_grant_id' => $grant->id, 'product_file_id' => $file->id]);
    }

    #[Test]
    public function deleting_an_order_deletes_its_grants_and_a_removed_product_leaves_them(): void
    {
        $product = Product::factory()->create();
        $order = Order::factory()->create();
        $item = $order->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => '1.00', 'subtotal' => '1.00']);
        $grant = DownloadGrant::create(['order_id' => $order->id, 'order_item_id' => $item->id, 'product_id' => $product->id]);

        // The line's product (and so the grant's) can be removed without losing the order's history.
        DB::table('order_items')->where('id', $item->id)->update(['product_id' => null]);
        DB::table('products')->where('id', $product->id)->delete();
        $this->assertNull($grant->fresh()->product_id);

        $order->delete();

        $this->assertSame(0, DownloadGrant::query()->count());
    }
}
