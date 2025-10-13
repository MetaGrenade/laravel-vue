<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('subscriptions')) {
            return;
        }

        if (Schema::hasColumn('subscriptions', 'owner_id') && ! Schema::hasColumn('subscriptions', 'user_id')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable()->after('id');
            });

            DB::table('subscriptions')
                ->whereNull('user_id')
                ->update(['user_id' => DB::raw('owner_id')]);

            Schema::table('subscriptions', function (Blueprint $table) {
                $table->dropIndex(['owner_id', 'owner_type']);
                $table->dropIndex(['owner_id', 'owner_type', 'name']);
            });

            Schema::table('subscriptions', function (Blueprint $table) {
                $table->dropColumn(['owner_id', 'owner_type']);
            });

            Schema::table('subscriptions', function (Blueprint $table) {
                $table->index(['user_id', 'name']);
            });
        }

        Schema::table('subscriptions', function (Blueprint $table) {
            $dropColumns = array_filter([
                Schema::hasColumn('subscriptions', 'payment_method') ? 'payment_method' : null,
                Schema::hasColumn('subscriptions', 'coupon') ? 'coupon' : null,
                Schema::hasColumn('subscriptions', 'metadata') ? 'metadata' : null,
            ]);

            if ($dropColumns !== []) {
                $table->dropColumn($dropColumns);
            }
        });

        // The legacy stub schema allowed nullable ownership columns. New installations
        // receive the proper not-null constraint via the base migration; existing
        // databases keep the populated nullable column for compatibility.
    }

    public function down(): void
    {
        // This migration is one-way; previous stub schema is no longer supported.
    }
};
