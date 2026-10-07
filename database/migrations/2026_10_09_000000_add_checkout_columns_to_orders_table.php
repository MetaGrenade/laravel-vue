<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Turns the placeholder orders table into one that checkout can write to:
 * a public identifier and order number, an owner (a morph so a team can own
 * orders in 1.1), customer details, payment state and lifecycle timestamps.
 *
 * Existing rows (for example demo data) are backfilled so they look like
 * orders created by checkout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('public_id', 26)->nullable()->unique();
            $table->string('number', 32)->nullable()->unique();
            $table->nullableMorphs('owner');
            $table->string('customer_email')->nullable()->index();
            $table->string('customer_name')->nullable();
            $table->string('payment_provider', 32)->nullable();
            $table->string('payment_status', 32)->default('unpaid')->index();
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->timestamp('placed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Indexes first: SQLite cannot drop a column that is still indexed.
            $table->dropUnique(['public_id']);
            $table->dropUnique(['number']);
            $table->dropUnique(['idempotency_key']);
            $table->dropIndex(['customer_email']);
            $table->dropIndex(['payment_status']);
            $table->dropIndex(['expires_at']);
            $table->dropMorphs('owner');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'public_id',
                'number',
                'customer_email',
                'customer_name',
                'payment_provider',
                'payment_status',
                'idempotency_key',
                'placed_at',
                'paid_at',
                'fulfilled_at',
                'cancelled_at',
                'expires_at',
            ]);
        });
    }

    private function backfill(): void
    {
        $prefix = (string) config('commerce.orders.number_prefix', 'MF');

        DB::table('orders')
            ->orderBy('id')
            ->each(function (object $order) use ($prefix) {
                // Orders that were already moving through fulfilment were paid for.
                $paid = in_array($order->status, ['processing', 'completed'], true);

                DB::table('orders')->where('id', $order->id)->update([
                    'public_id' => strtolower((string) Str::ulid()),
                    'number' => $prefix.'-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT),
                    'owner_type' => $order->user_id ? (new User)->getMorphClass() : null,
                    'owner_id' => $order->user_id,
                    'payment_status' => $paid ? 'paid' : 'unpaid',
                    'placed_at' => $order->created_at,
                    'paid_at' => $paid ? $order->created_at : null,
                    'fulfilled_at' => $order->status === 'completed' ? $order->updated_at : null,
                    'cancelled_at' => $order->status === 'cancelled' ? $order->updated_at : null,
                ]);
            });
    }
};
