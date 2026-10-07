<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Webhook calls were Stripe-only (stripe_id). Commerce adds more providers, so
 * calls are identified by (provider, external_id), which also makes handling a
 * redelivered event idempotent. stripe_id stays for the existing admin screens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_webhook_calls', function (Blueprint $table) {
            $table->string('provider', 32)->default('stripe');
            $table->string('external_id')->nullable();
            $table->boolean('signature_valid')->default(true);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
        });

        DB::table('billing_webhook_calls')
            ->whereNotNull('stripe_id')
            ->update(['external_id' => DB::raw('stripe_id')]);

        Schema::table('billing_webhook_calls', function (Blueprint $table) {
            $table->unique(['provider', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('billing_webhook_calls', function (Blueprint $table) {
            $table->dropUnique(['provider', 'external_id']);
        });

        Schema::table('billing_webhook_calls', function (Blueprint $table) {
            $table->dropColumn(['provider', 'external_id', 'signature_valid', 'attempts', 'error']);
        });
    }
};
