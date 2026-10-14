<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gift cards: stored value a customer spends at checkout.
 *
 * A card is a bearer instrument, so its code is never kept: only a SHA-256 hash to find it by (the code has
 * 80 bits of randomness, so the hash cannot be worked back to it) and the last four characters to
 * recognise it by. The balance is kept on the card, and every change to it is written to an append-only
 * ledger in the same transaction, so the two can always be checked against each other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gift_cards', function (Blueprint $table) {
            $table->id();
            $table->char('code_hash', 64)->unique();
            $table->char('last_four', 4)->index();
            $table->char('currency', 3);
            $table->decimal('initial_amount', 10, 2);
            $table->decimal('balance', 10, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamp('expires_at')->nullable();
            // For the team only: who it is for, why it was issued.
            $table->string('note', 500)->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('gift_card_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gift_card_id')->constrained()->cascadeOnDelete();
            // issued, redeemed, released, refunded or adjusted.
            $table->string('type', 16);
            // Signed: what spending takes off is negative.
            $table->decimal('amount', 10, 2);
            $table->decimal('balance_after', 10, 2);
            // An order that is gone leaves the entry, so the history of the card stays whole.
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at')->nullable();

            // The history of one card, and what one order did to its card.
            $table->index(['gift_card_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_card_transactions');
        Schema::dropIfExists('gift_cards');
    }
};
