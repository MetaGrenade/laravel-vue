<?php

namespace Tests\Feature\Database;

use App\Models\Entitlement;
use App\Models\Order;
use App\Models\TebexPackage;
use App\Models\TebexPackageEntitlement;
use App\Models\UserEntitlement;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The entitlement tables on every database CI supports.
 *
 * DDL commits the test's transaction on MySQL, so a test that changes the schema puts it back before it ends. A statement
 * that is expected to fail runs in a savepoint: PostgreSQL abandons the whole transaction after one.
 */
class EntitlementsMigrationsTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_22_000000_create_entitlements_tables.php');
    }

    #[Test]
    public function the_tables_roll_back_and_forward_cleanly(): void
    {
        $this->assertTrue(Schema::hasColumns('entitlements', ['key', 'label', 'description', 'type']));
        $this->assertTrue(Schema::hasColumns('tebex_package_entitlements', ['tebex_package_id', 'entitlement_id', 'value']));
        $this->assertTrue(Schema::hasColumns('user_entitlements', ['owner_type', 'owner_id', 'entitlement_id', 'source', 'order_id', 'order_item_id', 'value', 'expires_at', 'revoked_at', 'revoked_reason']));

        // What plans give points at the entitlements, so it lets go first and takes hold last (MySQL refuses to drop a table
        // something still refers to).
        $plans = require database_path('migrations/2026_10_23_000000_create_subscription_plan_entitlements_table.php');

        $plans->down();
        $this->migration()->down();

        $this->assertFalse(Schema::hasTable('user_entitlements'));
        $this->assertFalse(Schema::hasTable('tebex_package_entitlements'));
        $this->assertFalse(Schema::hasTable('entitlements'));

        $this->migration()->up();
        $plans->up();

        $this->assertTrue(Schema::hasTable('user_entitlements'));
        $this->assertTrue(Schema::hasTable('subscription_plan_entitlements'));
    }

    #[Test]
    public function an_entitlement_is_found_by_its_key_and_the_key_is_used_once(): void
    {
        Entitlement::factory()->create(['key' => 'reports']);

        try {
            DB::transaction(fn () => Entitlement::factory()->create(['key' => 'reports']));
            $this->fail('A second entitlement with the same key was accepted.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(1, Entitlement::query()->where('key', 'reports')->count());
    }

    #[Test]
    public function a_package_gives_an_entitlement_once(): void
    {
        $package = TebexPackage::factory()->create();
        $entitlement = Entitlement::factory()->create();
        TebexPackageEntitlement::create(['tebex_package_id' => $package->id, 'entitlement_id' => $entitlement->id]);

        try {
            DB::transaction(fn () => TebexPackageEntitlement::create(['tebex_package_id' => $package->id, 'entitlement_id' => $entitlement->id]));
            $this->fail('A package was set to give the same entitlement twice.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(1, TebexPackageEntitlement::query()->count());
    }

    #[Test]
    public function an_order_line_gives_an_entitlement_once_but_grants_with_no_order_line_are_not_limited(): void
    {
        $order = Order::factory()->create();
        $item = $order->items()->create(['description' => 'VIP rank', 'quantity' => 1, 'unit_price' => '1.00', 'subtotal' => '1.00']);
        $entitlement = Entitlement::factory()->create();
        UserEntitlement::factory()->create(['entitlement_id' => $entitlement->id, 'order_id' => $order->id, 'order_item_id' => $item->id]);

        try {
            DB::transaction(fn () => UserEntitlement::factory()->create(['entitlement_id' => $entitlement->id, 'order_id' => $order->id, 'order_item_id' => $item->id]));
            $this->fail('An order line gave the same entitlement twice.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        // Given by hand, there is no order line to be unique by.
        UserEntitlement::factory()->count(2)->create(['entitlement_id' => $entitlement->id]);

        $this->assertSame(3, UserEntitlement::query()->count());
    }

    #[Test]
    public function a_grant_outlives_its_order_and_a_package_or_entitlement_that_goes_takes_what_depends_on_it(): void
    {
        $order = Order::factory()->create();
        $item = $order->items()->create(['description' => 'VIP rank', 'quantity' => 1, 'unit_price' => '1.00', 'subtotal' => '1.00']);
        $package = TebexPackage::factory()->create();
        $entitlement = Entitlement::factory()->create();
        $link = TebexPackageEntitlement::create(['tebex_package_id' => $package->id, 'entitlement_id' => $entitlement->id]);
        $grant = UserEntitlement::factory()->create(['entitlement_id' => $entitlement->id, 'order_id' => $order->id, 'order_item_id' => $item->id]);

        $this->assertNull($grant->owner_type, 'a guest order has no owner to give it to yet');

        // The grant is the account's; the order only explains it.
        $order->delete();
        $this->assertNull($grant->fresh()->order_id);
        $this->assertNull($grant->fresh()->order_item_id);

        // What a package gives goes with the package.
        $package->delete();
        $this->assertModelMissing($link);

        // And with the entitlement go the grants of it.
        $entitlement->delete();
        $this->assertModelMissing($grant);
    }

    #[Test]
    public function the_dates_of_a_grant_may_be_past_2038(): void
    {
        $grant = UserEntitlement::factory()->create(['expires_at' => '2099-06-15 12:00:00', 'revoked_at' => '2099-01-01 00:00:00', 'revoked_reason' => 'staff'])->fresh();

        $this->assertSame('2099-06-15', $grant->expires_at->format('Y-m-d'));
        $this->assertSame('2099-01-01', $grant->revoked_at->format('Y-m-d'));
    }
}
