<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Licence keys: a pool of secrets kept for a product's (or a variant's) stock, each handed to one
 * paid order line.
 *
 * A key belongs to the stock record it is sold from, so the pool and the stock count are about the
 * same thing: the count follows the number of unused keys. While a key has no order line it is in
 * the pool; once it has one it is the customer's and never goes back, even if the order is refunded
 * or the key is withdrawn, because the customer may have used it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licence_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_item_id')->constrained()->cascadeOnDelete();
            // The key itself, encrypted: whoever can read the database but not APP_KEY learns nothing.
            $table->text('value');
            // A keyed hash of the key, so adding the same key twice is noticed without decrypting anything.
            $table->char('fingerprint', 64);
            // Set when the key is given to an ordered line, which takes it out of the pool. If the line
            // is ever deleted the key goes with it rather than being sold a second time.
            $table->foreignId('order_item_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamp('issued_at')->nullable();
            // Set when the customer may no longer see the key, and why: `refunded` (the order was refunded
            // in full; cleared again if that refund later fails) or `staff` (never cleared automatically).
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 20)->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The same key twice in one pool is a mistake; the same text in two different pools is not.
            $table->unique(['fingerprint', 'inventory_item_id']);
            // Counting and picking unused keys for a stock record.
            $table->index(['inventory_item_id', 'order_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licence_keys');
    }
};
