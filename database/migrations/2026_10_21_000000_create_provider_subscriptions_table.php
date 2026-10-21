<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A provider-neutral mirror of a recurring payment kept at a provider that is not Cashier (Tebex's recurring packages).
 *
 * The provider owns the subscription: it charges, retries and ends it, and tells the shop by webhook. This row is only
 * what the shop has been told, so that staff and the customer can see where it stands, and it is written by the webhook
 * handler and nothing else. It is found by the provider's own reference (unique with the provider), and it belongs to
 * whoever owns the order that started it (a guest's has no owner until the order is claimed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('owner');
            // The order whose basket started it. Kept if the order goes, since the subscription is its own record.
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tebex_package_id')->nullable()->constrained('tebex_packages')->nullOnDelete();
            $table->string('provider', 32);
            // The provider's id for it ("tbx-r-...").
            $table->string('reference');
            // What it was bought as, as it was then.
            $table->string('package_name')->nullable();
            $table->string('status', 32)->default('active')->index();
            // What the provider called the status, for staff (its own words).
            $table->string('provider_status')->nullable();
            $table->decimal('amount', 10, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->unsignedInteger('renewals_count')->default(0);
            $table->unsignedInteger('fail_count')->default(0);
            // Every date here is the provider's to give, so none is a TIMESTAMP: on MySQL that stops at 2038, and a date
            // past it would make the webhook fail (and be retried) for good.
            $table->dateTime('started_at')->nullable();
            $table->dateTime('last_payment_at')->nullable();
            $table->dateTime('next_payment_at')->nullable();
            $table->dateTime('cancellation_requested_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            // Set once, when the provider says it has ended: whatever it granted is to be taken back.
            $table->dateTime('ended_at')->nullable();
            // When the newest delivery that changed it was sent, so that a late one cannot undo a newer one.
            $table->dateTime('last_event_at')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_subscriptions');
    }
};
