<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wishlists and product reviews.
 *
 * Both belong to a person rather than to an account that could be shared (a wishlist is one customer's list,
 * a review is one customer's opinion), so they carry `user_id` and not the `owner_type` / `owner_id` pair that
 * orders and payments use. Deleting the account deletes both, and deleting a product deletes what was said
 * about it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wishlist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // A product is saved once.
            $table->unique(['user_id', 'product_id']);
        });

        Schema::create('product_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->string('title', 120)->nullable();
            $table->text('body');
            // pending (waiting for staff), published or rejected (kept, never shown to the public).
            $table->string('status', 16)->default('pending');
            // The reviewer had a paid order for the product when they wrote it.
            $table->boolean('verified_purchase')->default(false);
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();
            $table->timestamps();

            // One review per customer and product (writing again changes it).
            $table->unique(['product_id', 'user_id']);
            // What the product page lists and counts, and what staff filter by.
            $table->index(['product_id', 'status', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_reviews');
        Schema::dropIfExists('wishlist_items');
    }
};
