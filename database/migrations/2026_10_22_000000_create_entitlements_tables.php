<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a purchase gives an account in the shop (a feature switched on, a limit raised), whichever provider took the payment.
 *
 * - `entitlements` is the list of things that can be given. Code asks for one by its `key`, so the key does not change.
 * - `tebex_package_entitlements` says what each Tebex package gives (and, for a limit, how much per unit bought). Other
 *   sources (Stripe plans, the shop's own products) get a table of the same shape when they are wired in.
 * - `user_entitlements` are the grants: one per order line and entitlement, written when the order is paid and taken back
 *   (`revoked_at`) when the money or the recurring payment behind it goes. A grant belongs to whoever owns the order, and a
 *   guest's has no owner until the order is claimed, like the order's payments do.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entitlements', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('label');
            $table->text('description')->nullable();
            // feature | limit
            $table->string('type', 16);
            $table->timestamps();
        });

        Schema::create('tebex_package_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tebex_package_id')->constrained('tebex_packages')->cascadeOnDelete();
            $table->foreignId('entitlement_id')->constrained('entitlements')->cascadeOnDelete();
            // For a limit: how much one unit of the package adds. Null for a feature.
            $table->unsignedInteger('value')->nullable();
            $table->timestamps();

            // Named by hand: the name Laravel would make is over 64 characters, which is all MySQL allows.
            $table->unique(['tebex_package_id', 'entitlement_id'], 'tebex_package_entitlement_unique');
        });

        Schema::create('user_entitlements', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('owner');
            $table->foreignId('entitlement_id')->constrained('entitlements')->cascadeOnDelete();
            // Who gave it: the payment provider of the order ("tebex"), or "admin" for one given by hand.
            $table->string('source', 32);
            // Kept if the order goes: the grant is the account's, the order only explains it.
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained('order_items')->nullOnDelete();
            // For a limit: what it adds (the package's value times the quantity bought). Null for a feature.
            $table->unsignedInteger('value')->nullable();
            // Dates are DATETIME, not TIMESTAMP: on MySQL that stops at 2038.
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            // Why it was taken back ("refund", "subscription"), so that it can be given back when the reason goes away.
            $table->string('revoked_reason', 32)->nullable();
            $table->timestamps();

            // An order line gives an entitlement once, however many times the payment is reported.
            $table->unique(['order_item_id', 'entitlement_id']);
            $table->index('revoked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_entitlements');
        Schema::dropIfExists('tebex_package_entitlements');
        Schema::dropIfExists('entitlements');
    }
};
