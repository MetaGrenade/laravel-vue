<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a subscription plan gives the account that holds it (a feature switched on, a limit raised), the Stripe side of what
 * `tebex_package_entitlements` does for Tebex packages.
 *
 * Unlike a purchase, nothing is written when someone subscribes: the account holds whatever the plans of its live
 * subscriptions give right now, worked out when it is asked, so changing a plan or a subscription reaches the people on it
 * at once and there is no copy to fall out of step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plan_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_plan_id')->constrained('subscription_plans')->cascadeOnDelete();
            $table->foreignId('entitlement_id')->constrained('entitlements')->cascadeOnDelete();
            // For a limit: how much the plan gives. Null for a feature.
            $table->unsignedInteger('value')->nullable();
            $table->timestamps();

            // Named by hand: the name Laravel would make is over 64 characters, which is all MySQL allows.
            $table->unique(['subscription_plan_id', 'entitlement_id'], 'plan_entitlement_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plan_entitlements');
    }
};
