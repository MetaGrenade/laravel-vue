<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            // Financial records must not disappear with an order.
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->nullableMorphs('owner');
            $table->string('provider', 32);
            // The provider's id for this attempt (a Stripe Checkout session id).
            $table->string('provider_reference');
            // The provider's id for the money movement (a Stripe PaymentIntent id).
            $table->string('provider_payment_id')->nullable()->index();
            $table->string('status', 32)->default('pending')->index();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3);
            $table->decimal('fee', 10, 2)->nullable();
            $table->text('checkout_url')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
