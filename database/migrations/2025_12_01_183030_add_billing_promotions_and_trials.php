<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public bool $withinTransaction = false;

    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('discount_type');
            $table->unsignedInteger('amount_off')->nullable();
            $table->unsignedTinyInteger('percent_off')->nullable();
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('redeemed_count')->default(0);
            $table->unsignedInteger('min_amount')->default(0);
            $table->unsignedBigInteger('subscription_plan_id')->nullable();
            $table->unsignedInteger('bonus_trial_days')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->foreign('subscription_plan_id')
                ->references('id')
                ->on('subscription_plans')
                ->nullOnDelete();
        });

        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('coupon_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->unsignedInteger('discount_amount')->default(0);
            $table->unsignedInteger('trial_days')->default(0);
            $table->timestamp('redeemed_at');
            $table->timestamps();

            $table->foreign('coupon_id')->references('id')->on('coupons')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->nullOnDelete();
            $table->unique(['coupon_id', 'user_id']);
        });

        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->unsignedInteger('trial_days')->default(0)->after('interval');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedBigInteger('coupon_id')->nullable()->after('stripe_price');
            $table->string('promo_code')->nullable()->after('coupon_id');
            $table->timestamp('trial_started_at')->nullable()->after('quantity');

            $table->foreign('coupon_id')
                ->references('id')
                ->on('coupons')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropForeign(['coupon_id']);
            $table->dropColumn(['coupon_id', 'promo_code', 'trial_started_at']);
        });

        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn('trial_days');
        });

        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('coupons');
    }
};
