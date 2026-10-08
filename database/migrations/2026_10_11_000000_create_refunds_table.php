<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money handed back to a customer. A refund is a record of its own (not a
 * number on the order) because it has a life of its own: it can be pending at
 * the provider, fail, or be made outside the shop, and each one must be
 * traceable to who asked for it and why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            // Financial records must not disappear with an order or a payment.
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            // The staff member who issued it; empty for a refund made at the provider.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Empty for a refund recorded by hand (cash, bank transfer).
            $table->string('provider', 32)->nullable();
            // The provider's id for the refund (a Stripe "re_…").
            $table->string('provider_reference')->nullable();
            // Sent to the provider so repeating the request cannot refund twice.
            $table->string('idempotency_key', 64)->unique();
            $table->string('status', 32)->default('pending')->index();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3);
            $table->string('reason', 32)->nullable();
            $table->text('note')->nullable();
            $table->string('failure_reason')->nullable();
            $table->boolean('restock')->default(false);
            $table->boolean('notify_customer')->default(false);
            // When it first succeeded: the moment the customer is told.
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
