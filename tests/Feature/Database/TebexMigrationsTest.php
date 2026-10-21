<?php

namespace Tests\Feature\Database;

use App\Models\Cart;
use App\Models\Order;
use App\Models\ProviderSubscription;
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

        // The cart and order lines, the recurring payments and what the packages give point at the packages, so they let go
        // first and take hold last (MySQL refuses to drop a table something still refers to).
        $lines = require database_path('migrations/2026_10_20_000000_add_tebex_packages_to_cart_and_order_items.php');
        $subscriptions = require database_path('migrations/2026_10_21_000000_create_provider_subscriptions_table.php');
        $entitlements = require database_path('migrations/2026_10_22_000000_create_entitlements_tables.php');

        $entitlements->down();
        $subscriptions->down();
        $lines->down();
        $this->migration()->down();

        $this->assertFalse(Schema::hasTable('tebex_syncs'));
        $this->assertFalse(Schema::hasTable('tebex_packages'));
        $this->assertFalse(Schema::hasTable('tebex_categories'));

        $this->migration()->up();
        $lines->up();
        $subscriptions->up();
        $entitlements->up();

        $this->assertTrue(Schema::hasTable('tebex_packages'));
        $this->assertTrue(Schema::hasTable('user_entitlements'));
        $this->assertTrue(Schema::hasColumn('cart_items', 'tebex_package_id'));
        $this->assertTrue(Schema::hasTable('provider_subscriptions'));
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

    #[Test]
    public function the_recurring_payment_mirror_rolls_back_and_forward_cleanly(): void
    {
        $migration = require database_path('migrations/2026_10_21_000000_create_provider_subscriptions_table.php');

        $this->assertTrue(Schema::hasColumns('provider_subscriptions', [
            'owner_type', 'owner_id', 'order_id', 'tebex_package_id', 'provider', 'reference', 'package_name', 'status', 'provider_status',
            'amount', 'currency', 'renewals_count', 'fail_count', 'started_at', 'last_payment_at', 'next_payment_at',
            'cancellation_requested_at', 'cancelled_at', 'cancel_reason', 'ended_at', 'last_event_at', 'raw',
        ]));

        $migration->down();

        $this->assertFalse(Schema::hasTable('provider_subscriptions'));

        $migration->up();

        $this->assertTrue(Schema::hasTable('provider_subscriptions'));
    }

    #[Test]
    public function a_provider_has_a_recurring_payment_once_but_two_providers_may_share_a_reference(): void
    {
        ProviderSubscription::factory()->create(['reference' => 'ref-1']);
        ProviderSubscription::factory()->create(['provider' => 'other', 'reference' => 'ref-1']);

        try {
            DB::transaction(fn () => ProviderSubscription::factory()->create(['reference' => 'ref-1']));
            $this->fail('A second recurring payment with the same provider and reference was accepted.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(2, ProviderSubscription::query()->count());
    }

    #[Test]
    public function a_recurring_payment_outlives_its_order_and_its_package_and_needs_no_owner(): void
    {
        $package = TebexPackage::factory()->subscription()->create();
        $order = Order::factory()->create();
        $subscription = ProviderSubscription::factory()->create(['order_id' => $order->id, 'tebex_package_id' => $package->id]);

        $this->assertNull($subscription->owner_type, 'a guest\'s has no owner until the order is claimed');
        $this->assertSame($order->id, $subscription->order->id);
        $this->assertSame($package->id, $subscription->package->id);

        $package->delete();
        $order->delete();

        $subscription = $subscription->fresh();
        $this->assertNotNull($subscription, 'it is the provider\'s record, not the order\'s');
        $this->assertNull($subscription->order_id);
        $this->assertNull($subscription->tebex_package_id);
        $this->assertSame('MVP (monthly)', $subscription->package_name, 'what it was bought as is kept');
    }

    #[Test]
    public function a_recurring_payment_keeps_its_cents_its_dates_and_hides_what_the_provider_sent(): void
    {
        $subscription = ProviderSubscription::factory()->create([
            'amount' => '4.5',
            'raw' => ['reference' => 'tbx-r-1', 'price' => ['amount' => 4.5]],
            'ended_at' => '2026-12-01 10:00:00',
            // Past 2038, where a MySQL TIMESTAMP stops: Tebex's dates are not the shop's to limit.
            'next_payment_at' => '2099-06-15 12:00:00',
        ])->fresh();

        $this->assertSame('4.50', $subscription->amount);
        $this->assertSame('2026-12-01', $subscription->ended_at->format('Y-m-d'));
        $this->assertSame('2099-06-15', $subscription->next_payment_at->format('Y-m-d'));
        $this->assertSame('tbx-r-1', $subscription->makeVisible('raw')->raw['reference']);
        $this->assertArrayNotHasKey('raw', $subscription->fresh()->toArray(), 'the webhook body is for staff\'s eyes at most, never serialised by accident');
    }
}
