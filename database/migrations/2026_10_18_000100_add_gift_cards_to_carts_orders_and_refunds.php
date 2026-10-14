<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A cart holds the gift card its owner applied (so it works for guests, and is checked again whenever the
 * cart is priced). An order keeps the card and how much of the total it paid: `grand_total` stays what
 * the order is worth, and what the payment provider is asked for is `grand_total - gift_card_total`.
 *
 * Orders and refunds refuse to lose their card: one that has been used can be disabled, never deleted.
 * A refund with a `gift_card_id` is money put back on that card rather than sent through a provider.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->foreignId('gift_card_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('gift_card_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('gift_card_total', 10, 2)->default(0);
        });

        Schema::table('refunds', function (Blueprint $table) {
            $table->foreignId('gift_card_id')->nullable()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->dropConstrainedForeignId('gift_card_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('gift_card_id');
            $table->dropColumn('gift_card_total');
        });

        Schema::table('carts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('gift_card_id');
        });
    }
};
