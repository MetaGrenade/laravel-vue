<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the shop ships and what it costs. A zone is a set of countries (or "*",
 * everywhere else); each zone offers one or more rates. Amounts are in the
 * store currency (config/commerce.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_zones', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // A list of ISO country codes, or ["*"] for every country not covered by another zone.
            $table->json('countries');
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('shipping_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipping_zone_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->decimal('amount', 10, 2)->default(0);
            // The rate is only offered when the shippable items' subtotal is within these limits
            // (for example "free over 100": a zero-amount rate with a minimum of 100).
            $table->decimal('min_subtotal', 10, 2)->nullable();
            $table->decimal('max_subtotal', 10, 2)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_rates');
        Schema::dropIfExists('shipping_zones');
    }
};
