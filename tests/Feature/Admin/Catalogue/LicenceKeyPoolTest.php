<?php

namespace Tests\Feature\Admin\Catalogue;

use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\LicenceKey;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\Commerce\Catalogue\CatalogueException;
use App\Support\Commerce\Catalogue\CatalogueRemover;
use App\Support\Commerce\Catalogue\StockAdjuster;
use App\Support\Commerce\Digital\LicenceKeys;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithCommerce;
use Tests\TestCase;

/**
 * The pool of licence keys behind a product's stock: switching it on, adding and deleting keys, and the
 * rule that the stock count follows the pool and cannot be edited by hand.
 */
class LicenceKeyPoolTest extends TestCase
{
    use InteractsWithCommerce;
    use RefreshDatabase;

    private LicenceKeys $keys;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->setUpCommerce();
        $this->keys = app(LicenceKeys::class);
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('admin');
    }

    private function staff(string ...$permissions): User
    {
        $user = User::factory()->create()->assignRole('editor');
        $user->givePermissionTo(['commerce.acp.view', ...$permissions]);

        return $user;
    }

    private function stock(Product $product): InventoryItem
    {
        return InventoryItem::query()->where('product_id', $product->id)->whereNull('product_variant_id')->firstOrFail();
    }

    // --- Switching it on and off ---------------------------------------------------------------------

    #[Test]
    public function switching_keys_on_creates_the_stock_record_at_zero(): void
    {
        $product = Product::factory()->digital()->create();

        $this->actingAs($this->admin())->post(route('acp.commerce.licence-keys.enable', $product))->assertSessionHas('success');

        $stock = $this->stock($product);
        $this->assertTrue($stock->delivers_keys);
        $this->assertSame(0, $stock->quantity);
        $this->assertFalse($stock->allow_backorder);
    }

    #[Test]
    public function switching_keys_on_for_stock_that_was_counted_by_hand_starts_the_pool_at_zero_and_says_so(): void
    {
        $product = Product::factory()->digital()->stocked(40, allowBackorder: true)->create();

        $this->keys->enable($product, null, $this->admin());

        $stock = $this->stock($product);
        $this->assertSame(0, $stock->quantity, 'the count is now the number of keys, and there are none');
        $this->assertFalse($stock->allow_backorder, 'a key cannot be sold before it exists');

        $movement = InventoryMovement::query()->where('inventory_item_id', $stock->id)->sole();
        $this->assertSame(-40, $movement->delta);
        $this->assertSame('Stock now follows the licence keys', $movement->note);
    }

    #[Test]
    public function switching_on_twice_changes_nothing(): void
    {
        $product = Product::factory()->digital()->keyed(2)->create();

        $this->keys->enable($product, null);

        $this->assertSame(2, $this->stock($product)->quantity);
        $this->assertSame(2, LicenceKey::query()->count());
    }

    #[Test]
    public function it_cannot_be_switched_on_while_orders_for_the_product_wait_for_payment(): void
    {
        $product = Product::factory()->priced('10.00')->digital()->stocked(5)->create();
        $this->cartWith($product);
        $this->post(route('shop.checkout.store'), $this->checkoutPayload())->assertRedirect();
        $this->assertSame(4, $this->stock($product)->quantity, 'the order holds a unit');

        $this->expectException(CatalogueException::class);

        try {
            $this->keys->enable($product, null);
        } finally {
            $stock = $this->stock($product);
            $this->assertFalse($stock->delivers_keys);
            $this->assertSame(4, $stock->quantity, 'refused, so nothing changed');
        }
    }

    #[Test]
    public function a_variant_has_its_own_pool(): void
    {
        $product = Product::factory()->digital()->create();
        $variant = ProductVariant::factory()->for($product)->create();

        $this->actingAs($this->admin())->post(route('acp.commerce.licence-keys.enable', $product), ['product_variant_id' => $variant->id])->assertSessionHas('success');

        $stock = InventoryItem::query()->where('product_variant_id', $variant->id)->sole();
        $this->assertTrue($stock->delivers_keys);
        $this->assertSame(0, InventoryItem::query()->whereNull('product_variant_id')->count(), 'the product itself has no stock record');
    }

    #[Test]
    public function a_variant_of_another_product_is_refused(): void
    {
        $product = Product::factory()->digital()->create();
        $foreign = ProductVariant::factory()->create();

        $this->actingAs($this->admin())->post(route('acp.commerce.licence-keys.enable', $product), ['product_variant_id' => $foreign->id])->assertNotFound();
    }

    #[Test]
    public function keys_cannot_be_switched_off_while_any_are_in_the_pool_or_owed(): void
    {
        $product = Product::factory()->digital()->keyed(2)->create();
        $stock = $this->stock($product);

        try {
            $this->keys->disable($stock);
            $this->fail('the pool still holds keys');
        } catch (CatalogueException $exception) {
            $this->assertStringContainsString('2 unused keys', $exception->getMessage());
        }

        $this->assertTrue($stock->fresh()->delivers_keys);
    }

    #[Test]
    public function an_empty_pool_can_be_switched_off_and_the_stock_is_ordinary_again(): void
    {
        $product = Product::factory()->digital()->keyed(1)->create();
        $stock = $this->stock($product);
        $this->keys->remove($stock, LicenceKey::query()->pluck('id')->all());

        $this->actingAs($this->admin())->post(route('acp.commerce.licence-keys.disable', $stock->id))->assertSessionHas('success');

        $this->assertFalse($stock->fresh()->delivers_keys);

        // Counted by hand again.
        $this->app->make(StockAdjuster::class)->change($stock->fresh(), 5, 'Delivery');
        $this->assertSame(5, $stock->fresh()->quantity);
    }

    // --- Adding keys -------------------------------------------------------------------------------

    #[Test]
    public function keys_are_added_one_per_line_and_raise_the_count(): void
    {
        $product = Product::factory()->digital()->keyed()->create();
        $stock = $this->stock($product);

        $this->actingAs($this->admin())
            ->post(route('acp.commerce.licence-keys.store', $stock->id), ['keys' => "AAAA-1111\r\n\r\n  BBBB-2222  \nCCCC-3333\n"])
            ->assertSessionHas('success', '3 licence keys added.');

        $this->assertSame(3, $stock->fresh()->quantity);
        $this->assertEqualsCanonicalizing(['AAAA-1111', 'BBBB-2222', 'CCCC-3333'], LicenceKey::all()->map(fn (LicenceKey $key) => $key->value)->all());
        $this->assertSame(3, LicenceKey::query()->unused()->count());

        $movement = InventoryMovement::query()->where('inventory_item_id', $stock->id)->sole();
        $this->assertSame(3, $movement->delta);
        $this->assertSame('3 licence keys added', $movement->note);
        $this->assertSame(InventoryMovement::ADJUSTMENT, $movement->reason);
    }

    #[Test]
    public function the_same_key_twice_is_added_once_and_a_repeat_is_skipped(): void
    {
        $product = Product::factory()->digital()->keyed()->create();
        $stock = $this->stock($product);

        $first = $this->keys->add($stock, "ONE\nTWO\nONE");
        $second = $this->keys->add($stock, "TWO\nTHREE");

        $this->assertSame(['added' => 2, 'duplicates' => 0], $first, 'a repeat within the same paste is dropped before it counts');
        $this->assertSame(['added' => 1, 'duplicates' => 1], $second);
        $this->assertSame(3, $stock->fresh()->quantity);
        $this->assertSame(3, LicenceKey::query()->count());
    }

    #[Test]
    public function the_same_text_may_be_a_key_in_two_different_pools(): void
    {
        $one = Product::factory()->digital()->keyed()->create();
        $two = Product::factory()->digital()->keyed()->create();

        $this->keys->add($this->stock($one), 'SHARED-KEY');
        $result = $this->keys->add($this->stock($two), 'SHARED-KEY');

        $this->assertSame(1, $result['added']);
        $this->assertSame(2, LicenceKey::query()->count());
    }

    #[Test]
    public function a_key_is_encrypted_in_the_database_and_never_serialised(): void
    {
        $product = Product::factory()->digital()->keyed()->create();
        $this->keys->add($this->stock($product), 'SECRET-KEY-12345');

        $raw = DB::table('licence_keys')->sole();

        $this->assertStringNotContainsString('SECRET-KEY-12345', $raw->value, 'whoever reads the database learns nothing');
        $this->assertStringNotContainsString('SECRET-KEY-12345', $raw->fingerprint);
        $this->assertSame(hash_hmac('sha256', 'SECRET-KEY-12345', config('app.key')), $raw->fingerprint);

        $key = LicenceKey::sole();
        $this->assertSame('SECRET-KEY-12345', $key->value, 'the model decrypts it');
        $this->assertStringNotContainsString('SECRET-KEY-12345', $key->toJson(), 'and does not put it in JSON by accident');
        $this->assertArrayNotHasKey('value', $key->toArray());
    }

    #[Test]
    public function a_masked_key_shows_only_its_end(): void
    {
        $this->assertSame(str_repeat('•', 4), (new LicenceKey(['value' => 'abc']))->masked(), 'a short key shows nothing');
        $this->assertSame(str_repeat('•', 10).'6789', (new LicenceKey(['value' => 'ABCD-EFGH-6789']))->masked());
        $this->assertSame(str_repeat('•', 12).'6789', (new LicenceKey(['value' => str_repeat('X', 60).'6789']))->masked(), 'never more than a few dots');
    }

    #[Test]
    public function nothing_is_added_when_any_line_is_unacceptable(): void
    {
        $product = Product::factory()->digital()->keyed()->create();
        $stock = $this->stock($product);
        config(['commerce.licence_keys.max_per_upload' => 3]);

        foreach ([
            '' => 'Enter at least one licence key',
            "   \n\n" => 'Enter at least one licence key',
            "OK-1\n".str_repeat('x', 256) => 'at most 255 characters',
            "OK-1\nbad\tkey" => 'control characters',
            "A\nB\nC\nD" => 'at most 3 keys at a time',
        ] as $text => $message) {
            try {
                $this->keys->add($stock, (string) $text);
                $this->fail("{$message}: should have been refused");
            } catch (CatalogueException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }
        }

        $this->assertSame(0, LicenceKey::query()->count());
        $this->assertSame(0, $stock->fresh()->quantity);
    }

    #[Test]
    public function keys_cannot_be_added_to_stock_that_does_not_deliver_them(): void
    {
        $product = Product::factory()->digital()->stocked(3)->create();

        $this->actingAs($this->admin())
            ->post(route('acp.commerce.licence-keys.store', $this->stock($product)->id), ['keys' => 'ONE'])
            ->assertSessionHasErrors('keys');

        $this->assertSame(0, LicenceKey::query()->count());
        $this->assertSame(3, $this->stock($product)->quantity);
    }

    // --- Deleting unused keys ------------------------------------------------------------------------

    #[Test]
    public function unused_keys_can_be_deleted_and_the_count_drops_with_them(): void
    {
        $product = Product::factory()->digital()->keyed(4)->create();
        $stock = $this->stock($product);
        $ids = LicenceKey::query()->orderBy('id')->limit(2)->pluck('id')->all();

        $this->actingAs($this->admin())
            ->delete(route('acp.commerce.licence-keys.destroy', $stock->id), ['ids' => $ids])
            ->assertSessionHas('success', '2 licence keys deleted.');

        $this->assertSame(2, LicenceKey::query()->count());
        $this->assertSame(2, $stock->fresh()->quantity);
        $this->assertSame(-2, InventoryMovement::query()->where('inventory_item_id', $stock->id)->latest('id')->first()->delta);
    }

    #[Test]
    public function a_key_that_was_issued_is_never_deleted_from_here(): void
    {
        $product = Product::factory()->priced('10.00')->digital()->keyed(2)->create();
        $stock = $this->stock($product);
        $order = Order::factory()->paid()->create();
        $item = $order->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => '10.00', 'subtotal' => '10.00', 'delivers_keys' => true, 'requires_shipping' => false]);
        $issued = LicenceKey::query()->orderBy('id')->first();
        $issued->forceFill(['order_item_id' => $item->id, 'issued_at' => now()])->save();

        $this->assertSame(0, $this->keys->remove($stock, [$issued->id]));

        $this->assertNotNull(LicenceKey::query()->find($issued->id));
    }

    #[Test]
    public function keys_that_waiting_orders_hold_a_unit_for_cannot_be_deleted(): void
    {
        $product = Product::factory()->priced('10.00')->digital()->keyed(2)->create();
        $stock = $this->stock($product);

        // An order waiting for payment holds one of the two units.
        $this->cartWith($product);
        $this->post(route('shop.checkout.store'), $this->checkoutPayload())->assertRedirect();
        $this->assertSame(1, $stock->fresh()->quantity);

        // Both keys are unused, but only one unit is free: deleting both would leave the order with no key.
        try {
            $this->keys->remove($stock, LicenceKey::query()->pluck('id')->all());
            $this->fail('the order is spoken for');
        } catch (CatalogueException $exception) {
            $this->assertStringContainsString('Orders are waiting', $exception->getMessage());
        }

        $this->assertSame(2, LicenceKey::query()->count(), 'nothing was deleted');
        $this->assertSame(1, $stock->fresh()->quantity);

        // One can go.
        $this->assertSame(1, $this->keys->remove($stock, [LicenceKey::query()->orderByDesc('id')->value('id')]));
        $this->assertSame(0, $stock->fresh()->quantity);
    }

    // --- The count follows the pool ------------------------------------------------------------------

    #[Test]
    public function the_count_of_a_keyed_stock_cannot_be_edited_by_hand(): void
    {
        $product = Product::factory()->digital()->keyed(3)->create();
        $stock = $this->stock($product);
        $adjuster = $this->app->make(StockAdjuster::class);

        foreach ([
            fn () => $adjuster->set($stock, 99),
            fn () => $adjuster->change($stock, 5),
            fn () => $adjuster->change($stock, -1),
            fn () => $adjuster->untrack($stock),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('a keyed count must not be edited');
            } catch (CatalogueException) {
                $this->assertSame(3, $stock->fresh()->quantity);
            }
        }

        $this->assertNotNull($stock->fresh(), 'and it cannot be untracked, which would delete the pool');
        $this->assertSame(3, LicenceKey::query()->count());
    }

    #[Test]
    public function the_stock_screen_refuses_to_change_a_keyed_count_or_to_sell_past_zero(): void
    {
        $product = Product::factory()->digital()->keyed(3)->create();
        $stock = $this->stock($product);
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('acp.commerce.stock.update', $stock->id), ['mode' => 'set', 'quantity' => 50])->assertSessionHasErrors('quantity');
        $this->actingAs($admin)->put(route('acp.commerce.stock.update', $stock->id), ['allow_backorder' => true])->assertSessionHasErrors('quantity');
        $this->actingAs($admin)->delete(route('acp.commerce.stock.destroy', $stock->id))->assertSessionHas('error');

        $stock->refresh();
        $this->assertSame(3, $stock->quantity);
        $this->assertFalse($stock->allow_backorder);
        $this->assertTrue($stock->delivers_keys);
    }

    // --- Deleting what the keys belong to --------------------------------------------------------------

    #[Test]
    public function a_product_that_still_holds_keys_cannot_be_deleted_until_they_are_gone(): void
    {
        $product = Product::factory()->digital()->keyed(2)->create();
        $remover = app(CatalogueRemover::class);

        $this->assertStringContainsString('2 licence keys', (string) $remover->productBlock($product));

        try {
            $remover->deleteProduct($product);
            $this->fail('the keys would be lost');
        } catch (CatalogueException) {
            // expected
        }
        $this->assertSame(2, LicenceKey::query()->count());

        $this->keys->remove($this->stock($product), LicenceKey::query()->pluck('id')->all());
        $remover->deleteProduct($product);

        $this->assertNull(Product::query()->find($product->id));
    }

    #[Test]
    public function a_variant_that_still_holds_keys_cannot_be_deleted_until_they_are_gone(): void
    {
        $product = Product::factory()->digital()->create();
        $variant = ProductVariant::factory()->for($product)->keyed(1)->create();

        $this->assertStringContainsString('1 licence key.', (string) app(CatalogueRemover::class)->variantBlock($variant));
    }

    // --- The product page ----------------------------------------------------------------------------

    #[Test]
    public function the_product_page_lists_where_keys_can_be_sold_and_never_sends_a_whole_key(): void
    {
        $product = Product::factory()->digital()->create();
        $standard = ProductVariant::factory()->for($product)->keyed(2)->create(['name' => 'Standard']);
        ProductVariant::factory()->for($product)->create(['name' => 'Pro']);
        $secret = "KEY-1-v{$standard->id}";

        $response = $this->actingAs($this->admin())->get(route('acp.commerce.products.edit', $product))
            ->assertInertia(fn (Assert $page) => $page
                ->has('licence_targets', 2)
                ->where('licence_targets.0.label', 'Standard')
                ->where('licence_targets.0.enabled', true)
                ->where('licence_targets.0.counts', ['unused' => 2, 'issued' => 0, 'withdrawn' => 0, 'waiting' => 0])
                ->has('licence_targets.0.unused', 2)
                ->where('licence_targets.0.unused.0.masked', fn ($masked) => str_contains($masked, '•') && ! str_contains($masked, 'KEY'))
                ->where('licence_targets.1.label', 'Pro')
                ->where('licence_targets.1.enabled', false)
                ->where('licence_targets.1.counts', null)
                ->where('licence_rules.max_per_upload', 1000));

        $this->assertStringNotContainsString($secret, $response->getContent(), 'staff see the end of a key, not the key');
    }

    #[Test]
    public function a_product_without_variants_offers_itself(): void
    {
        $product = Product::factory()->digital()->create();

        $this->actingAs($this->admin())->get(route('acp.commerce.products.edit', $product))
            ->assertInertia(fn (Assert $page) => $page
                ->has('licence_targets', 1)
                ->where('licence_targets.0.label', 'This product')
                ->where('licence_targets.0.variant_id', null)
                ->where('licence_targets.0.enabled', false));
    }

    #[Test]
    public function the_readiness_checklist_says_when_the_pool_is_empty(): void
    {
        $product = Product::factory()->priced('9.00')->digital()->keyed(0)->create();

        $this->actingAs($this->admin())->get(route('acp.commerce.products.edit', $product))
            ->assertInertia(fn (Assert $page) => $page->where('readiness', fn ($checks) => collect($checks)->contains(fn ($check) => $check['ok'] === false && str_contains($check['text'], 'No unused licence keys'))));

        $this->keys->add($this->stock($product), "A\nB");

        $this->actingAs($this->admin())->get(route('acp.commerce.products.edit', $product))
            ->assertInertia(fn (Assert $page) => $page->where('readiness', fn ($checks) => collect($checks)->contains(fn ($check) => $check['ok'] === true && str_contains($check['text'], '2 unused licence keys ready to sell'))));
    }

    #[Test]
    public function the_stock_row_says_when_its_count_follows_keys(): void
    {
        $product = Product::factory()->digital()->keyed(1)->create();

        $this->actingAs($this->admin())->get(route('acp.commerce.products.edit', $product))
            ->assertInertia(fn (Assert $page) => $page->where('stock.delivers_keys', true)->where('stock.quantity', 1));
    }

    // --- Who may do what -----------------------------------------------------------------------------

    #[Test]
    public function each_action_needs_its_own_permission(): void
    {
        $product = Product::factory()->digital()->keyed(2)->create();
        $stock = $this->stock($product);
        $viewer = $this->staff();

        $this->actingAs($viewer)->post(route('acp.commerce.licence-keys.store', $stock->id), ['keys' => 'NEW-1'])->assertForbidden();
        $this->actingAs($viewer)->post(route('acp.commerce.licence-keys.enable', $product))->assertForbidden();
        $this->actingAs($viewer)->post(route('acp.commerce.licence-keys.disable', $stock->id))->assertForbidden();
        $this->actingAs($viewer)->delete(route('acp.commerce.licence-keys.destroy', $stock->id), ['ids' => [1]])->assertForbidden();
        $this->assertSame(2, LicenceKey::query()->count());

        $viewer->givePermissionTo('commerce.acp.create');
        $this->actingAs($viewer->fresh())->post(route('acp.commerce.licence-keys.store', $stock->id), ['keys' => 'NEW-1'])->assertSessionHas('success');
        $this->actingAs($viewer->fresh())->delete(route('acp.commerce.licence-keys.destroy', $stock->id), ['ids' => [1]])->assertForbidden();

        $viewer->givePermissionTo('commerce.acp.delete');
        $this->actingAs($viewer->fresh())->delete(route('acp.commerce.licence-keys.destroy', $stock->id), ['ids' => [LicenceKey::query()->orderBy('id')->value('id')]])->assertSessionHas('success');
    }

    #[Test]
    public function the_destroy_request_is_validated(): void
    {
        $product = Product::factory()->digital()->keyed(1)->create();
        $stock = $this->stock($product);
        $admin = $this->admin();

        $this->actingAs($admin)->delete(route('acp.commerce.licence-keys.destroy', $stock->id), [])->assertSessionHasErrors('ids');
        $this->actingAs($admin)->delete(route('acp.commerce.licence-keys.destroy', $stock->id), ['ids' => ['x']])->assertSessionHasErrors('ids.0');
        $this->actingAs($admin)->post(route('acp.commerce.licence-keys.store', $stock->id), ['keys' => ''])->assertSessionHasErrors('keys');
        $this->assertSame(1, LicenceKey::query()->count());
    }
}
