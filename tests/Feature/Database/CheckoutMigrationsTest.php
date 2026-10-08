<?php

namespace Tests\Feature\Database;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Existing installations already have orders and webhook calls. These tests put
 * the database back into that older shape, add rows the way the old code did,
 * and apply the new migrations to them, on every database CI supports.
 */
class CheckoutMigrationsTest extends TestCase
{
    use RefreshDatabase;

    private function migration(string $file): object
    {
        return require database_path("migrations/{$file}.php");
    }

    /**
     * Assert that a statement is refused by a unique index.
     *
     * PostgreSQL aborts the surrounding transaction when a statement fails, and
     * its DDL is transactional, so the test's transaction is still open and the
     * statement needs a savepoint. MySQL commits implicitly on DDL, which ends
     * the test's transaction (a savepoint would then not exist), and SQLite
     * simply rolls back the failed statement. So only PostgreSQL gets one.
     */
    private function assertRefusedAsDuplicate(callable $statement, string $message): void
    {
        try {
            if (DB::getDriverName() === 'pgsql') {
                DB::transaction($statement);
            } else {
                $statement();
            }
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail($message);
    }

    #[Test]
    public function existing_orders_are_backfilled_by_the_checkout_columns_migration(): void
    {
        $migration = $this->migration('2026_10_09_000000_add_checkout_columns_to_orders_table');
        $user = User::factory()->create();
        $ids = [];

        $migration->down();

        try {
            $this->assertFalse(Schema::hasColumn('orders', 'public_id'));

            $created = '2026-01-10 09:00:00';
            $updated = '2026-01-12 15:30:00';

            foreach ([
                'pending' => $user->id,
                'processing' => $user->id,
                'completed' => $user->id,
                'cancelled' => null,
            ] as $status => $userId) {
                $ids[$status] = DB::table('orders')->insertGetId([
                    'user_id' => $userId,
                    'status' => $status,
                    'currency' => 'USD',
                    'grand_total' => '10.00',
                    'created_at' => $created,
                    'updated_at' => $updated,
                ]);
            }

            $migration->up();

            $orders = DB::table('orders')->whereIn('id', $ids)->get()->keyBy('status');

            // Every order is addressable and numbered, and the values are unique.
            $this->assertCount(4, $orders->pluck('public_id')->unique());
            foreach ($orders as $order) {
                $this->assertSame(26, strlen($order->public_id));
                $this->assertSame(strtolower($order->public_id), $order->public_id);
                $this->assertSame('MF-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT), $order->number);
            }

            // Orders placed by a user are owned by that user; a guest order has no owner.
            $this->assertSame($user->getMorphClass(), $orders['processing']->owner_type);
            $this->assertEquals($user->id, $orders['processing']->owner_id);
            $this->assertNull($orders['cancelled']->owner_type);
            $this->assertNull($orders['cancelled']->owner_id);

            // Payment state follows what the old status implied.
            $this->assertSame('unpaid', $orders['pending']->payment_status);
            $this->assertSame('paid', $orders['processing']->payment_status);
            $this->assertSame('paid', $orders['completed']->payment_status);
            $this->assertSame('unpaid', $orders['cancelled']->payment_status);

            $this->assertSame($created, $orders['processing']->placed_at);
            $this->assertSame($created, $orders['processing']->paid_at);
            $this->assertNull($orders['pending']->paid_at);
            $this->assertSame($updated, $orders['completed']->fulfilled_at);
            $this->assertSame($updated, $orders['cancelled']->cancelled_at);

            // The new columns exist with their indexes: a duplicate public id is refused.
            $this->assertRefusedAsDuplicate(
                fn () => DB::table('orders')->where('id', $ids['pending'])->update(['public_id' => $orders['processing']->public_id]),
                'public ids must be unique',
            );
        } finally {
            if (! Schema::hasColumn('orders', 'public_id')) {
                $migration->up();
            }

            DB::table('orders')->whereIn('id', $ids)->delete();
            $user->delete();
        }
    }

    #[Test]
    public function the_checkout_columns_migration_rolls_back_and_forward_cleanly(): void
    {
        $migration = $this->migration('2026_10_09_000000_add_checkout_columns_to_orders_table');

        $migration->down();

        $this->assertFalse(Schema::hasColumn('orders', 'payment_status'));
        $this->assertFalse(Schema::hasColumn('orders', 'owner_type'));
        $this->assertTrue(Schema::hasColumn('orders', 'grand_total'), 'the original columns are untouched');

        $migration->up();

        $this->assertTrue(Schema::hasColumns('orders', ['public_id', 'number', 'owner_type', 'owner_id', 'payment_status', 'expires_at']));
    }

    #[Test]
    public function existing_webhook_calls_gain_a_provider_and_external_id(): void
    {
        $migration = $this->migration('2026_10_09_000300_generalise_billing_webhook_calls_table');
        $ids = [];

        $migration->down();

        try {
            $this->assertFalse(Schema::hasColumn('billing_webhook_calls', 'external_id'));

            $ids[] = DB::table('billing_webhook_calls')->insertGetId([
                'stripe_id' => 'evt_legacy_1',
                'type' => 'invoice.payment_succeeded',
                'payload' => '{}',
                'processed_at' => '2026-01-10 09:00:00',
                'created_at' => '2026-01-10 09:00:00',
                'updated_at' => '2026-01-10 09:00:00',
            ]);
            // Calls stored without a Stripe id (older rows) must survive too.
            $ids[] = DB::table('billing_webhook_calls')->insertGetId([
                'stripe_id' => null,
                'type' => 'unknown',
                'payload' => '{}',
                'created_at' => '2026-01-10 09:00:00',
                'updated_at' => '2026-01-10 09:00:00',
            ]);

            $migration->up();

            $legacy = DB::table('billing_webhook_calls')->find($ids[0]);
            $this->assertSame('stripe', $legacy->provider);
            $this->assertSame('evt_legacy_1', $legacy->external_id);
            $this->assertSame(0, (int) $legacy->attempts);
            $this->assertTrue((bool) $legacy->signature_valid);
            $this->assertNull($legacy->error);

            $orphan = DB::table('billing_webhook_calls')->find($ids[1]);
            $this->assertNull($orphan->external_id);

            // (provider, external_id) is now unique, which is what makes redelivery idempotent.
            $this->assertRefusedAsDuplicate(
                fn () => DB::table('billing_webhook_calls')->insert([
                    'provider' => 'stripe',
                    'external_id' => 'evt_legacy_1',
                    'type' => 'invoice.payment_succeeded',
                    'payload' => '{}',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]),
                'a repeated (provider, external_id) must be refused',
            );

            // The same external id from another provider is fine.
            $ids[] = DB::table('billing_webhook_calls')->insertGetId([
                'provider' => 'tebex',
                'external_id' => 'evt_legacy_1',
                'type' => 'payment.completed',
                'payload' => '{}',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } finally {
            if (! Schema::hasColumn('billing_webhook_calls', 'external_id')) {
                $migration->up();
            }

            DB::table('billing_webhook_calls')->whereIn('id', $ids)->delete();
        }
    }

    #[Test]
    public function the_new_tables_can_be_rolled_back_and_recreated(): void
    {
        $tables = [
            'payments' => '2026_10_09_000100_create_payments_table',
            'inventory_movements' => '2026_10_09_000200_create_inventory_movements_table',
            'refunds' => '2026_10_11_000000_create_refunds_table',
            'order_events' => '2026_10_11_000100_create_order_events_table',
        ];

        // Newest first, as `migrate:rollback` goes, so no table is dropped while another still
        // refers to it (refunds point at payments). Then back again, oldest first.
        foreach (array_reverse($tables, true) as $table => $file) {
            $this->migration($file)->down();
            $this->assertFalse(Schema::hasTable($table), "{$table} should be gone");
        }

        foreach ($tables as $table => $file) {
            $this->migration($file)->up();
            $this->assertTrue(Schema::hasTable($table), "{$table} should be back");
        }
    }

    #[Test]
    public function the_refunded_total_column_rolls_back_and_forward_cleanly(): void
    {
        $migration = $this->migration('2026_10_11_000200_add_refunded_total_to_orders_table');

        $migration->down();
        $this->assertFalse(Schema::hasColumn('orders', 'refunded_total'));

        $migration->up();
        $this->assertTrue(Schema::hasColumn('orders', 'refunded_total'));

        // Orders placed before refunds existed start with nothing refunded.
        $id = DB::table('orders')->insertGetId(['status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame('0.00', number_format((float) DB::table('orders')->where('id', $id)->value('refunded_total'), 2, '.', ''));
    }
}
