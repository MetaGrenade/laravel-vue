<?php

namespace Tests\Feature\Commerce;

use App\Models\Product;
use App\Models\ProductReview;
use App\Models\ProductVariant;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WishlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithCommerce;
use Tests\TestCase;

/**
 * A signed-in customer saving products to come back to.
 */
class WishlistTest extends TestCase
{
    use InteractsWithCommerce;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerce();
    }

    private function product(string $price = '10.00'): Product
    {
        return Product::factory()->priced($price)->stocked(10)->create();
    }

    private function save(User $user, Product $product)
    {
        return $this->actingAs($user)->post(route('shop.wishlist.store'), ['product_id' => $product->id]);
    }

    // --- Who can use it ----------------------------------------------------------------------------

    #[Test]
    public function every_action_needs_a_signed_in_customer(): void
    {
        $product = $this->product();

        $this->get(route('shop.wishlist'))->assertRedirect(route('login'));
        $this->post(route('shop.wishlist.store'), ['product_id' => $product->id])->assertRedirect(route('login'));
        $this->delete(route('shop.wishlist.destroy', $product))->assertRedirect(route('login'));

        $this->assertSame(0, WishlistItem::query()->count());
    }

    #[Test]
    public function the_shop_section_being_off_hides_it(): void
    {
        SystemSetting::set('website_sections', ['blog' => true, 'forum' => true, 'support' => true, 'commerce' => false]);
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('shop.wishlist'))->assertNotFound();
        $this->save($user, $this->product())->assertNotFound();
    }

    // --- Saving and removing -----------------------------------------------------------------------

    #[Test]
    public function a_product_can_be_saved(): void
    {
        $user = User::factory()->create();
        $product = $this->product();

        $this->save($user, $product)->assertRedirect()->assertSessionHas('success', 'Saved to your wishlist.');

        $this->assertDatabaseHas('wishlist_items', ['user_id' => $user->id, 'product_id' => $product->id]);
    }

    #[Test]
    public function saving_a_product_twice_is_the_same_as_once(): void
    {
        $user = User::factory()->create();
        $product = $this->product();

        $this->save($user, $product);
        $this->save($user, $product)->assertSessionHas('success', 'Already on your wishlist.');

        $this->assertSame(1, WishlistItem::query()->where('user_id', $user->id)->count());
    }

    #[Test]
    public function a_product_that_is_not_on_sale_cannot_be_saved(): void
    {
        $user = User::factory()->create();
        $off = Product::factory()->inactive()->create();

        $this->save($user, $off)->assertSessionHas('error', 'This product is not available.');
        $this->actingAs($user)->post(route('shop.wishlist.store'), ['product_id' => 999999])->assertSessionHas('error');
        $this->actingAs($user)->post(route('shop.wishlist.store'), [])->assertSessionHasErrors('product_id');

        $this->assertSame(0, WishlistItem::query()->count());
    }

    #[Test]
    public function the_list_has_a_limit_and_a_full_list_still_accepts_what_it_already_holds(): void
    {
        config(['commerce.wishlist.max_items' => 2]);
        $user = User::factory()->create();
        [$a, $b, $c] = [$this->product(), $this->product(), $this->product()];

        $this->save($user, $a);
        $this->save($user, $b);
        $this->save($user, $c)->assertSessionHas('error');
        $this->save($user, $a)->assertSessionHas('success', 'Already on your wishlist.');

        $this->assertSame(2, WishlistItem::query()->where('user_id', $user->id)->count());
        $this->assertDatabaseMissing('wishlist_items', ['product_id' => $c->id]);
    }

    #[Test]
    public function one_customers_list_is_not_another_customers(): void
    {
        $ada = User::factory()->create();
        $bob = User::factory()->create();
        $product = $this->product();

        $this->save($ada, $product);
        $this->save($bob, $product);
        $this->actingAs($ada)->delete(route('shop.wishlist.destroy', $product))->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseMissing('wishlist_items', ['user_id' => $ada->id]);
        $this->assertDatabaseHas('wishlist_items', ['user_id' => $bob->id, 'product_id' => $product->id]);

        // Removing what was never saved is harmless.
        $this->actingAs($ada)->delete(route('shop.wishlist.destroy', $product))->assertRedirect();
    }

    // --- The page ----------------------------------------------------------------------------------

    #[Test]
    public function the_page_lists_the_customers_products_newest_first(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        [$first, $second, $third] = [$this->product(), $this->product(), $this->product()];

        foreach ([$first, $second, $third] as $product) {
            $this->save($user, $product);
        }
        $this->save($other, $this->product());

        $this->actingAs($user)->get(route('shop.wishlist'))->assertInertia(fn (Assert $page) => $page
            ->component('commerce/Wishlist')
            ->has('products.data', 3)
            ->where('products.data.0.id', $third->id)
            ->where('products.data.1.id', $second->id)
            ->where('products.data.2.id', $first->id)
            ->where('products.data.0.wishlisted', true));
    }

    #[Test]
    public function a_product_that_is_switched_off_is_hidden_until_it_comes_back(): void
    {
        $user = User::factory()->create();
        $product = $this->product();
        $this->save($user, $product);

        $product->update(['is_active' => false]);
        $this->actingAs($user)->get(route('shop.wishlist'))->assertInertia(fn (Assert $page) => $page->has('products.data', 0));
        $this->assertDatabaseHas('wishlist_items', ['user_id' => $user->id, 'product_id' => $product->id]);

        $product->update(['is_active' => true]);
        $this->actingAs($user)->get(route('shop.wishlist'))->assertInertia(fn (Assert $page) => $page->has('products.data', 1));
    }

    #[Test]
    public function a_card_shows_what_the_catalogue_shows(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->stocked(5)->create();
        ProductVariant::factory()->for($product)->priced('12.00')->create(['is_default' => true]);
        ProductReview::factory()->for($product)->count(2)->state(['rating' => 4])->create();
        $this->save($user, $product);

        $this->actingAs($user)->get(route('shop.wishlist'))->assertInertia(fn (Assert $page) => $page
            ->has('products.data.0.variants', 1)
            ->where('products.data.0.rating', ['average' => 4, 'count' => 2])
            ->has('products.data.0.prices')
            ->where('products.data.0.slug', $product->slug));
    }

    #[Test]
    public function it_is_paginated(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 26) as $unused) {
            WishlistItem::factory()->create(['user_id' => $user->id, 'product_id' => $this->product()->id]);
        }

        $this->actingAs($user)->get(route('shop.wishlist'))->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 24)
            ->where('products.total', 26)
            ->where('products.last_page', 2));
        $this->actingAs($user)->get(route('shop.wishlist', ['page' => 2]))->assertInertia(fn (Assert $page) => $page->has('products.data', 2));
    }

    // --- Where else it shows -----------------------------------------------------------------------

    #[Test]
    public function the_catalogue_marks_what_the_customer_saved(): void
    {
        $user = User::factory()->create();
        $saved = Product::factory()->priced('5.00')->stocked(3)->create(['name' => 'Saved one']);
        $unsaved = Product::factory()->priced('6.00')->stocked(3)->create(['name' => 'Unsaved one']);
        $this->save($user, $saved);
        $this->save(User::factory()->create(), $unsaved);

        $flags = fn ($response) => collect($response->viewData('page')['props']['products']['data'])->pluck('wishlisted', 'name')->all();

        $this->assertSame(['Saved one' => true, 'Unsaved one' => false], $flags($this->actingAs($user)->get(route('shop.index'))));

        $this->app['auth']->forgetGuards();
        $this->assertSame(['Saved one' => false, 'Unsaved one' => false], $flags($this->get(route('shop.index'))), 'a guest has nothing saved');
    }

    #[Test]
    public function the_product_page_knows_whether_it_is_saved(): void
    {
        $user = User::factory()->create();
        $product = $this->product();

        $this->actingAs($user)->get(route('shop.products.show', $product))->assertInertia(fn (Assert $page) => $page->where('wishlisted', false));
        $this->save($user, $product);
        $this->actingAs($user)->get(route('shop.products.show', $product))->assertInertia(fn (Assert $page) => $page->where('wishlisted', true));

        $this->app['auth']->forgetGuards();
        $this->get(route('shop.products.show', $product))->assertInertia(fn (Assert $page) => $page->where('wishlisted', false));
    }

    #[Test]
    public function the_catalogue_costs_the_same_queries_however_many_products_there_are(): void
    {
        $user = User::factory()->create();
        $count = function () use ($user): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($user)->get(route('shop.index'))->assertOk();

            return count(DB::getQueryLog());
        };

        $one = $this->product();
        $this->save($user, $one);
        ProductReview::factory()->for($one)->create();
        $small = $count();

        foreach (range(1, 5) as $unused) {
            $product = $this->product();
            $this->save($user, $product);
            ProductReview::factory()->for($product)->create();
        }

        $this->assertSame($small, $count(), 'ratings and saved products are each fetched once for the whole page');
    }

    // --- Going away --------------------------------------------------------------------------------

    #[Test]
    public function a_deleted_product_or_account_takes_its_saved_entries_with_it(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $product = $this->product();
        $kept = $this->product();
        $this->save($user, $product);
        $this->save($other, $product);
        $this->save($user, $kept);

        $product->delete();
        $this->assertSame(1, WishlistItem::query()->count(), 'only the entry for the product that is left');

        $user->delete();
        $this->assertSame(0, WishlistItem::query()->count());
    }
}
