<?php

namespace Tests\Feature\Database;

use App\Models\Product;
use App\Models\ProductReview;
use App\Models\User;
use App\Models\WishlistItem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The wishlist and review tables on every database CI supports.
 *
 * DDL commits the test's transaction on MySQL, so a test that changes the schema puts it back before it ends.
 */
class WishlistAndReviewMigrationsTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_17_000000_create_wishlist_items_and_product_reviews_tables.php');
    }

    #[Test]
    public function the_tables_roll_back_and_forward_cleanly(): void
    {
        $this->assertTrue(Schema::hasColumns('wishlist_items', ['user_id', 'product_id']));
        $this->assertTrue(Schema::hasColumns('product_reviews', ['product_id', 'user_id', 'rating', 'title', 'body', 'status', 'verified_purchase', 'moderated_by', 'moderated_at']));

        $this->migration()->down();

        $this->assertFalse(Schema::hasTable('wishlist_items'));
        $this->assertFalse(Schema::hasTable('product_reviews'));

        $this->migration()->up();

        $this->assertTrue(Schema::hasTable('wishlist_items'));
        $this->assertTrue(Schema::hasTable('product_reviews'));
    }

    #[Test]
    public function a_product_is_saved_once_and_reviewed_once_by_the_database_itself(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();

        WishlistItem::query()->create(['user_id' => $user->id, 'product_id' => $product->id]);
        ProductReview::factory()->for($product)->for($user)->create();

        try {
            // In a savepoint: PostgreSQL abandons the whole transaction after a failed insert.
            DB::transaction(fn () => WishlistItem::query()->create(['user_id' => $user->id, 'product_id' => $product->id]));
            $this->fail('a second saved entry for the same product was accepted');
        } catch (QueryException) {
            $this->assertSame(1, WishlistItem::query()->count());
        }

        try {
            DB::transaction(fn () => ProductReview::factory()->for($product)->for($user)->create());
            $this->fail('a second review by the same customer was accepted');
        } catch (QueryException) {
            $this->assertSame(1, ProductReview::query()->count());
        }
    }

    #[Test]
    public function a_new_review_waits_for_approval_unless_told_otherwise(): void
    {
        $review = ProductReview::query()->create([
            'product_id' => Product::factory()->create()->id,
            'user_id' => User::factory()->create()->id,
            'rating' => 4,
            'body' => 'Written straight into the table.',
        ])->refresh();

        $this->assertSame('pending', $review->status->value);
        $this->assertFalse($review->verified_purchase);
    }
}
