<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `inventory_items.delivers_keys` says a stock record's count follows its pool of licence keys.
 *
 * `order_items.delivers_keys` remembers, when a line is bought, that it was sold as a licence key, so
 * the order owes keys for exactly the lines that promised them, even if the product is changed later.
 * Existing lines and stock are left as they were: they are not owed any keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->boolean('delivers_keys')->default(false);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->boolean('delivers_keys')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('delivers_keys');
        });

        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropColumn('delivers_keys');
        });
    }
};
