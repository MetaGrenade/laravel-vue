<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A cart or order line can be a package from the Tebex catalogue instead of a product. Its price, name and package are
 * kept on the line (an order never changes what it was bought as), and the link goes null if the package row is ever
 * removed. Packages are never deleted by the synchronisation, so in practice it does not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->foreignId('tebex_package_id')->nullable()->after('product_variant_id')->constrained('tebex_packages')->nullOnDelete();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('tebex_package_id')->nullable()->after('product_variant_id')->constrained('tebex_packages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tebex_package_id');
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tebex_package_id');
        });
    }
};
