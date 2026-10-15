<?php

namespace Tests\Feature\Database;

use App\Models\Cart;
use App\Models\Order;
use App\Models\TebexCategory;
use App\Models\TebexPackage;
use App\Models\TebexSync;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Tebex catalogue tables on every database CI supports.
 *
 * DDL commits the test's transaction on MySQL, so a test that changes the schema puts it back before it ends. A
 * statement that is expected to fail runs in a savepoint: PostgreSQL abandons the whole transaction after one.
 */
class TebexMigrationsTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_19_000000_create_tebex_catalogue_tables.php');
    }

    #[Test]
    public function the_tables_roll_back_and_forward_cleanly(): void
    {
        $this->assertTrue(Schema::hasColumns('tebex_categories', ['tebex_id', 'parent_tebex_id', 'name', 'slug', 'description', 'sort_order', 'display_type', 'image_url', 'is_dynamic', 'is_tiered', 'removed_at']));
        $this->assertTrue(Schema::hasColumns('tebex_packages', ['tebex_id', 'ident', 'tebex_category_id', 'name', 'type', 'base_price', 'sales_tax', 'total_price', 'currency', 'prorate_price', 'discount', 'media', 'options', 'variables', 'removed_at']));
        $this->assertTrue(Schema::hasColumns('tebex_syncs', ['trigger', 'status', 'user_id', 'started_at', 'finished_at', 'counts', 'error']));

        // The cart and order lines point at the packages, so they let go first and take hold last (MySQL refuses to
        // drop a table something still refers to).
        $lines = require database_path('migrations/2026_10_20_000000_add_tebex_packages_to_cart_and_order_items.php');

        $lines->down();
        $this->migration()->down();

        $this->assertFalse(Schema::hasTable('tebex_syncs'));
        $this->assertFalse(Schema::hasTable('tebex_packages'));
        $this->assertFalse(Schema::hasTable('tebex_categories'));

        $this->migration()->up();
        $lines->up();

        $this->assertTrue(Schema::hasTable('tebex_packages'));
        $this->assertTrue(Schema::hasColumn('cart_items', 'tebex_package_id'));
    }

    #[Test]
    public function a_tebex_id_is_in_the_copy_once(): void
    {
        TebexCategory::factory()->create(['tebex_id' => 7]);
        TebexPackage::factory()->create(['tebex_id' => 9]);

        foreach ([
            fn () => TebexCategory::factory()->create(['tebex_id' => 7]),
            fn () => TebexPackage::factory()->create(['tebex_id' => 9]),
        ] as $duplicate) {
            try {
                DB::transaction($duplicate);
                $this->fail('A second row with the same Tebex id was accepted.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(1, TebexCategory::query()->where('tebex_id', 7)->count());
        $this->assertSame(1, TebexPackage::query()->where('tebex_id', 9)->count());
    }

    #[Test]
    public function a_package_survives_losing_its_category_row_and_a_sync_survives_losing_its_user(): void
    {
        $category = TebexCategory::factory()->create();
        $package = TebexPackage::factory()->create(['tebex_category_id' => $category->id]);
        $user = User::factory()->create();
        $run = TebexSync::query()->create(['trigger' => TebexSync::ADMIN, 'status' => TebexSync::OK, 'user_id' => $user->id, 'started_at' => now()]);

        $category->delete();
        $user->delete();

        $this->assertNull($package->fresh()->tebex_category_id);
        $this->assertNull($run->fresh()->user_id);
    }

    #[Test]
    public function cart_and_order_lines_can_be_packages_and_lose_the_link_only_if_the_package_row_goes(): void
    {
        $package = TebexPackage::factory()->create();
        $cart = Cart::query()->create(['session_id' => Str::random(40), 'status' => 'open', 'currency' => 'USD']);
        $cartLine = $cart->items()->create(['tebex_package_id' => $package->id, 'quantity' => 1, 'unit_price' => '1.00', 'total' => '1.00']);
        $orderLine = Order::factory()->create()->items()->create(['tebex_package_id' => $package->id, 'quantity' => 1, 'unit_price' => '1.00', 'subtotal' => '1.00']);

        $this->assertNull($cartLine->fresh()->product_id, 'a package line is not a product');
        $this->assertSame($package->id, $cartLine->fresh()->tebexPackage->id);
        $this->assertSame($package->id, $orderLine->fresh()->tebexPackage->id);

        $package->delete();

        $this->assertNull($cartLine->fresh()->tebex_package_id);
        $this->assertNull($orderLine->fresh()->tebex_package_id);
        $this->assertSame(1, $orderLine->fresh()->quantity, 'the line itself stays: it says what was bought');
    }

    #[Test]
    public function the_package_columns_on_lines_roll_back_and_forward_cleanly(): void
    {
        $lines = require database_path('migrations/2026_10_20_000000_add_tebex_packages_to_cart_and_order_items.php');

        $this->assertTrue(Schema::hasColumn('cart_items', 'tebex_package_id'));
        $this->assertTrue(Schema::hasColumn('order_items', 'tebex_package_id'));

        $lines->down();

        $this->assertFalse(Schema::hasColumn('cart_items', 'tebex_package_id'));
        $this->assertFalse(Schema::hasColumn('order_items', 'tebex_package_id'));

        $lines->up();

        $this->assertTrue(Schema::hasColumn('cart_items', 'tebex_package_id'));
        $this->assertTrue(Schema::hasColumn('order_items', 'tebex_package_id'));
    }

    #[Test]
    public function prices_keep_their_cents_and_the_lists_come_back_as_lists(): void
    {
        $package = TebexPackage::factory()->create([
            'base_price' => '9.99',
            'media' => [['type' => 'image', 'url' => 'https://x.test/a.png']],
            'variables' => [['identifier' => 'server']],
        ])->fresh();

        $this->assertSame('9.99', $package->base_price);
        $this->assertSame('https://x.test/a.png', $package->media[0]['url']);
        $this->assertTrue($package->hasVariables());
        $this->assertNull($package->options);
    }
}
