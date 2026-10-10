<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Digital goods: the files a product delivers, the right to download them that a paid order gives
 * (a grant, one for each ordered line that has files), and how often each file was downloaded.
 *
 * Files live on a private disk and are only ever read by the download route, which checks the grant
 * first. A grant points at the product, not at particular files, so a file added to a product later
 * reaches the people who already bought it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            // What the customer sees and the name the file is saved under.
            $table->string('name', 255);
            $table->string('original_name', 255);
            $table->string('disk', 40);
            // A generated name with no extension: nothing a customer uploaded decides where it is kept.
            $table->string('path', 500);
            $table->unsignedBigInteger('size');
            $table->string('mime', 120)->nullable();
            // So a buyer can check the file arrived intact.
            $table->char('sha256', 64);
            // Switched off, a file is not offered to anyone (for example while it is being replaced).
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['product_id', 'position']);
        });

        Schema::create('download_grants', function (Blueprint $table) {
            $table->id();
            // What appears in the download link: unguessable, unlike the row id.
            $table->string('public_id', 26)->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            // One grant for each ordered line, so granting twice (a redelivered webhook) changes nothing.
            $table->foreignId('order_item_id')->unique()->constrained()->cascadeOnDelete();
            // The product whose files this order line may download (kept if the line's product is later removed).
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            // Set when access ends before it expires, and why: `refunded` (the order was refunded in
            // full; cleared again if that refund later fails) or `staff` (never cleared automatically).
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 20)->nullable();
            $table->timestamps();
        });

        Schema::create('download_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('download_grant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_file_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('downloads')->default(0);
            $table->timestamp('last_downloaded_at')->nullable();
            $table->timestamps();

            $table->unique(['download_grant_id', 'product_file_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('download_counts');
        Schema::dropIfExists('download_grants');
        Schema::dropIfExists('product_files');
    }
};
