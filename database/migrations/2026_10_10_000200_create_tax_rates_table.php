<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A configurable tax table. Prices are tax-exclusive: tax is added at checkout
 * for the country (and optionally region) the order goes to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            // An ISO country code, or "*" as the fallback for countries with no rate of their own.
            $table->string('country', 2);
            // Optional state or province, matched case-insensitively against the address.
            $table->string('region', 120)->nullable();
            // A percentage with up to four decimals (20 means 20%).
            $table->decimal('rate', 7, 4);
            $table->boolean('applies_to_shipping')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['country', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rates');
    }
};
