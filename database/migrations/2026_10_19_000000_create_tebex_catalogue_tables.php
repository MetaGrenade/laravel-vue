<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The shop's local copy of a Tebex store's catalogue, and a record of each time it was refreshed.
 *
 * Tebex owns the catalogue: rows here are written only by the synchronisation, never by hand. Nothing is ever
 * deleted, because an order (a later slice) refers to the package it was bought as: a category or package that
 * disappears from Tebex is stamped `removed_at` and comes back if Tebex lists it again. The ids Tebex gave are
 * kept in `tebex_id` and are what a row is matched by.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tebex_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tebex_id')->unique();
            // The parent as Tebex names it. Not a foreign key: a parent can arrive after its child, or never.
            $table->unsignedBigInteger('parent_tebex_id')->nullable()->index();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->longText('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('display_type', 16)->default('list');
            $table->text('image_url')->nullable();
            $table->boolean('is_dynamic')->default(false);
            $table->boolean('is_tiered')->default(false);
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tebex_packages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tebex_id')->unique();
            // What the Headless API takes to add the package to a basket.
            $table->string('ident')->nullable()->index();
            $table->foreignId('tebex_category_id')->nullable()->constrained('tebex_categories')->nullOnDelete();
            $table->string('name');
            $table->string('slug')->nullable();
            // HTML written in the Tebex panel: shown only after it has been cleaned.
            $table->longText('description')->nullable();
            $table->text('image_url')->nullable();
            // "single" or "subscription".
            $table->string('type', 16)->default('single');
            // Price before tax, the sales tax Tebex adds for this visitor's country, and what they pay.
            $table->decimal('base_price', 10, 2)->default(0);
            $table->decimal('sales_tax', 10, 2)->default(0);
            $table->decimal('total_price', 10, 2)->default(0);
            $table->char('currency', 3);
            $table->decimal('prorate_price', 10, 2)->nullable();
            $table->decimal('discount', 10, 2)->default(0);
            $table->boolean('disable_quantity')->default(false);
            $table->boolean('disable_gifting')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('user_limit')->default(0);
            $table->json('media')->nullable();
            $table->json('options')->nullable();
            // Choices the buyer must make (a server, a rank): a package that has any cannot be bought without them.
            $table->json('variables')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->index(['removed_at', 'sort_order']);
        });

        Schema::create('tebex_syncs', function (Blueprint $table) {
            $table->id();
            // What started it: "schedule", "command" or "admin".
            $table->string('trigger', 16);
            // "running", "ok" or "failed".
            $table->string('status', 16);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            // What changed: categories and packages created, updated, unchanged and removed.
            $table->json('counts')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tebex_syncs');
        Schema::dropIfExists('tebex_packages');
        Schema::dropIfExists('tebex_categories');
    }
};
