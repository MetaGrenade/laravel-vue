<?php

namespace Tests\Feature\Database;

use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The discount code migrations on every database CI supports.
 *
 * DDL commits the test's transaction on MySQL, so a test that changes the schema removes whatever
 * it created before it ends rather than relying on a rollback.
 */
class CouponMigrationsTest extends TestCase
{
    use RefreshDatabase;

    private function migration(string $file): object
    {
        return require database_path("migrations/{$file}.php");
    }

    private function rollBack(): void
    {
        // Newest first: the columns on carts and orders reference the coupons table.
        $this->migration('2026_10_14_000100_add_coupon_to_carts_and_orders')->down();
        $this->migration('2026_10_14_000000_create_coupons_tables')->down();
    }

    private function rebuild(): void
    {
        $this->migration('2026_10_14_000000_create_coupons_tables')->up();
        $this->migration('2026_10_14_000100_add_coupon_to_carts_and_orders')->up();
    }

    #[Test]
    public function the_coupon_tables_and_columns_roll_back_and_forward_cleanly(): void
    {
        $this->assertTrue(Schema::hasColumns('coupons', [
            'code', 'description', 'type', 'value', 'currency', 'minimum_subtotal', 'starts_at', 'ends_at',
            'max_redemptions', 'max_redemptions_per_customer', 'is_active',
        ]));
        $this->assertTrue(Schema::hasTable('coupon_product'));
        $this->assertTrue(Schema::hasTable('coupon_product_category'));
        $this->assertTrue(Schema::hasColumn('carts', 'coupon_id'));
        $this->assertTrue(Schema::hasColumns('orders', ['coupon_id', 'coupon_code']));

        $this->rollBack();

        $this->assertFalse(Schema::hasTable('coupons'));
        $this->assertFalse(Schema::hasTable('coupon_product'));
        $this->assertFalse(Schema::hasTable('coupon_product_category'));
        $this->assertFalse(Schema::hasColumn('carts', 'coupon_id'));
        $this->assertFalse(Schema::hasColumn('orders', 'coupon_id'));
        $this->assertFalse(Schema::hasColumn('orders', 'coupon_code'));

        $this->rebuild();

        $this->assertTrue(Schema::hasTable('coupons'));
        $this->assertTrue(Schema::hasColumns('orders', ['coupon_id', 'coupon_code']));
        $this->assertTrue(Schema::hasColumn('carts', 'coupon_id'));
    }

    #[Test]
    public function rolling_back_keeps_the_orders_and_carts_that_were_there(): void
    {
        $order = Order::factory()->create(['grand_total' => '42.00']);
        $cart = Cart::create(['session_id' => 'a-session', 'currency' => 'USD']);

        try {
            $this->rollBack();

            $this->assertEquals(42, DB::table('orders')->where('id', $order->id)->value('grand_total'));
            $this->assertTrue(DB::table('carts')->where('id', $cart->id)->exists());
        } finally {
            $this->rebuild();
            DB::table('orders')->where('id', $order->id)->delete();
            DB::table('carts')->where('id', $cart->id)->delete();
        }
    }

    #[Test]
    public function a_code_can_be_stored_only_once(): void
    {
        Coupon::factory()->create(['code' => 'SAVE10']);

        $this->expectException(QueryException::class);

        DB::table('coupons')->insert(['code' => 'SAVE10', 'type' => 'percent', 'created_at' => now(), 'updated_at' => now()]);
    }

    #[Test]
    public function a_coupon_that_orders_used_cannot_be_deleted(): void
    {
        $coupon = Coupon::factory()->create();
        Order::factory()->create(['coupon_id' => $coupon->id, 'coupon_code' => $coupon->code]);

        $this->expectException(QueryException::class);

        DB::table('coupons')->where('id', $coupon->id)->delete();
    }

    #[Test]
    public function deleting_an_unused_coupon_takes_it_off_carts_and_clears_its_limits(): void
    {
        $product = Product::factory()->create();
        $category = ProductCategory::create(['name' => 'Clothing', 'slug' => 'clothing']);
        $coupon = Coupon::factory()->forProducts([$product])->forCategories([$category])->create();
        $cart = Cart::create(['session_id' => 'a-session', 'currency' => 'USD', 'coupon_id' => $coupon->id]);

        $coupon->delete();

        $this->assertNull($cart->fresh()->coupon_id, 'the cart goes on, without the code');
        $this->assertSame(0, DB::table('coupon_product')->count());
        $this->assertSame(0, DB::table('coupon_product_category')->count());
        $this->assertModelExists($product);
        $this->assertModelExists($category);
    }

    #[Test]
    public function deleting_a_product_or_category_drops_it_from_a_codes_limits(): void
    {
        $product = Product::factory()->create();
        $category = ProductCategory::create(['name' => 'Clothing', 'slug' => 'clothing']);
        $coupon = Coupon::factory()->forProducts([$product])->forCategories([$category])->create();

        $product->delete();
        $category->delete();

        $this->assertSame(0, $coupon->products()->count());
        $this->assertSame(0, $coupon->categories()->count());
        $this->assertModelExists($coupon);
    }
}
