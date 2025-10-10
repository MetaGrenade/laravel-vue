<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('owner_id');
            $table->string('owner_type');
            $table->string('name');
            $table->string('stripe_id')->unique();
            $table->string('stripe_status')->nullable();
            $table->string('stripe_price')->nullable();
            $table->unsignedInteger('quantity')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('coupon')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['owner_id', 'owner_type']);
            $table->index(['owner_id', 'owner_type', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
