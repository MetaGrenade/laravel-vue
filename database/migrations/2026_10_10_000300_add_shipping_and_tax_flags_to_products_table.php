<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Digital goods set this to false: they are not shipped, so they need no
            // shipping address or shipping charge.
            $table->boolean('requires_shipping')->default(true);
            $table->boolean('is_taxable')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['requires_shipping', 'is_taxable']);
        });
    }
};
