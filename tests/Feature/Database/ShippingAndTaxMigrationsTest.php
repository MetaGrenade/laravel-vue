<?php

namespace Tests\Feature\Database;

use App\Models\ShippingRate;
use App\Models\ShippingZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Existing shops already have products and orders. These tests put the database
 * back into the older shape, add rows the way the old code did, and apply the
 * new migrations to them, on every database CI supports.
 */
class ShippingAndTaxMigrationsTest extends TestCase
{
    use RefreshDatabase;

    private function migration(string $file): object
    {
        return require database_path("migrations/{$file}.php");
    }

    #[Test]
    public function existing_products_stay_shippable_and_taxable(): void
    {
        $migration = $this->migration('2026_10_10_000300_add_shipping_and_tax_flags_to_products_table');
        $id = null;

        $migration->down();

        try {
            $this->assertFalse(Schema::hasColumn('products', 'requires_shipping'));

            $id = DB::table('products')->insertGetId([
                'name' => 'Legacy hoodie',
                'slug' => 'legacy-hoodie-'.uniqid(),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $migration->up();

            $product = DB::table('products')->find($id);

            // A product that existed before is a physical, taxable one: nothing changes for it.
            $this->assertTrue((bool) $product->requires_shipping);
            $this->assertTrue((bool) $product->is_taxable);
        } finally {
            if (! Schema::hasColumn('products', 'requires_shipping')) {
                $migration->up();
            }

            if ($id !== null) {
                DB::table('products')->where('id', $id)->delete();
            }
        }
    }

    #[Test]
    public function existing_orders_gain_empty_address_snapshots(): void
    {
        $migration = $this->migration('2026_10_10_000400_add_addresses_and_shipping_method_to_orders_table');
        $id = null;

        $migration->down();

        try {
            $this->assertFalse(Schema::hasColumn('orders', 'shipping_address'));

            $id = DB::table('orders')->insertGetId([
                'status' => 'processing',
                'payment_status' => 'paid',
                'currency' => 'USD',
                'grand_total' => '10.00',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $migration->up();

            $order = DB::table('orders')->find($id);

            $this->assertNull($order->shipping_address);
            $this->assertNull($order->billing_address);
            $this->assertNull($order->shipping_method);
            // A raw query returns a number on SQLite and a string on MySQL and PostgreSQL.
            $this->assertEqualsWithDelta(10.0, (float) $order->grand_total, 0.001, 'the order itself is untouched');
        } finally {
            if (! Schema::hasColumn('orders', 'shipping_address')) {
                $migration->up();
            }

            if ($id !== null) {
                DB::table('orders')->where('id', $id)->delete();
            }
        }
    }

    #[Test]
    public function the_new_tables_can_be_rolled_back_and_recreated(): void
    {
        $tables = [
            '2026_10_10_000200_create_tax_rates_table' => ['tax_rates'],
            '2026_10_10_000100_create_shipping_zones_and_rates_tables' => ['shipping_zones', 'shipping_rates'],
            '2026_10_10_000000_create_addresses_table' => ['addresses'],
        ];

        foreach ($tables as $file => $created) {
            $migration = $this->migration($file);

            $migration->down();
            foreach ($created as $table) {
                $this->assertFalse(Schema::hasTable($table), "{$table} should be gone after rolling back");
            }

            $migration->up();
            foreach ($created as $table) {
                $this->assertTrue(Schema::hasTable($table), "{$table} should exist again");
            }
        }
    }

    #[Test]
    public function deleting_a_zone_deletes_its_rates(): void
    {
        $zone = ShippingZone::factory()->serving(['GB'])->withRate('Standard', '5.00')->create();

        $this->assertSame(1, ShippingRate::count());

        $zone->delete();

        $this->assertSame(0, ShippingRate::count());
    }
}
