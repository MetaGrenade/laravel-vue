<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An order line remembers whether it had to be shipped when it was bought, so a paid order made only
 * of digital lines can be completed without anyone shipping anything, even if the product is later
 * changed or removed. Existing lines are treated as shipped: they were bought before this was known.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->boolean('requires_shipping')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('requires_shipping');
        });
    }
};
