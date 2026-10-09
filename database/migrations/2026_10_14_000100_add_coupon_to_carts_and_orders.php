<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A cart holds the code its owner applied, so it works for guests too and is checked again every
 * time the cart is priced. An order keeps the code (and, in its metadata, what the code was worth)
 * so a later edit to the coupon never changes an order that was placed.
 *
 * `orders.coupon_id` refuses a delete: a coupon that has been used can only be switched off, which
 * keeps the count of its uses intact. Counting a coupon's uses filters on `coupon_id`, which the
 * foreign key already indexes. (A second index on `(coupon_id, status)` cannot be dropped again on
 * MySQL, because the foreign key comes to depend on it.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('coupon_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('coupon_code', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coupon_id');
            $table->dropColumn('coupon_code');
        });

        Schema::table('carts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coupon_id');
        });
    }
};
