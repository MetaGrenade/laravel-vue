<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The catalogue migrations on every database CI supports.
 */
class CatalogueMigrationsTest extends TestCase
{
    use RefreshDatabase;

    private function migration(string $file): object
    {
        return require database_path("migrations/{$file}.php");
    }

    #[Test]
    public function the_variant_active_flag_rolls_back_and_forward_cleanly(): void
    {
        $migration = $this->migration('2026_10_12_000000_add_is_active_to_product_variants_table');

        $migration->down();
        $this->assertFalse(Schema::hasColumn('product_variants', 'is_active'));

        // A variant that existed before the flag was added goes on being sold.
        $productId = DB::table('products')->insertGetId(['name' => 'Old', 'slug' => 'old', 'created_at' => now(), 'updated_at' => now()]);
        $variantId = DB::table('product_variants')->insertGetId(['product_id' => $productId, 'name' => 'Base', 'created_at' => now(), 'updated_at' => now()]);

        $migration->up();

        $this->assertTrue(Schema::hasColumn('product_variants', 'is_active'));
        $this->assertTrue((bool) DB::table('product_variants')->where('id', $variantId)->value('is_active'));
    }
}
