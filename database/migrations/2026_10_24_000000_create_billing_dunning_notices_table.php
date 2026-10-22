<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The emails a customer was sent about a payment that failed, so that each is sent once.
 *
 * Stripe delivers a webhook again when it is not answered in time, an admin can replay one, and a payment can fail several
 * times before it goes through. A notice is claimed here first (one row per kind, invoice or subscription, and attempt), and
 * the email is sent only by whoever managed to claim it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_dunning_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // failed | recovered | cancelled
            $table->string('kind', 16);
            // The Stripe invoice (failed, recovered) or subscription (cancelled) the notice is about.
            $table->string('reference', 64);
            // Which attempt at the payment this was (failed only, 0 for the others): each attempt is told once.
            $table->unsignedSmallInteger('attempt')->default(0);
            $table->timestamps();

            $table->unique(['kind', 'reference', 'attempt'], 'billing_dunning_notice_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_dunning_notices');
    }
};
