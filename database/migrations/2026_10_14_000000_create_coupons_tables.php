<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Discount codes. A code is one of three kinds (a percentage off, a fixed amount off, or free
 * shipping) and may be limited by dates, by how many orders it can be used on, by how many of
 * those one customer can have, by a minimum spend and by the products or categories it applies to.
 *
 * Uses are not stored: they are counted from the orders that carry the code (see
 * `add_coupon_to_carts_and_orders`), so cancelling an order gives the use back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            // Upper-case, so a code is found however the customer types it.
            $table->string('code', 40)->unique();
            // A note for staff; customers only ever see the code.
            $table->string('description', 255)->nullable();
            $table->string('type', 20);
            // A percentage with up to four decimals (10 means 10%), or an amount in `currency`.
            $table->decimal('value', 12, 4)->nullable();
            $table->string('currency', 3)->nullable();
            $table->decimal('minimum_subtotal', 12, 2)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('max_redemptions_per_customer')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Optional restrictions: with none, the code applies to everything in the cart.
        Schema::create('coupon_product', function (Blueprint $table) {
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->primary(['coupon_id', 'product_id']);
        });

        Schema::create('coupon_product_category', function (Blueprint $table) {
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_category_id')->constrained()->cascadeOnDelete();
            $table->primary(['coupon_id', 'product_category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_product_category');
        Schema::dropIfExists('coupon_product');
        Schema::dropIfExists('coupons');
    }
};
