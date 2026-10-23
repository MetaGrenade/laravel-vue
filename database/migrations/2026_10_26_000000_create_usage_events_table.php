<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The ledger of metered usage: one row each time a customer uses something that is counted (see docs/usage.md).
     */
    public function up(): void
    {
        Schema::create('usage_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Which meter, by its key in config/billing.php.
            $table->string('meter', 64);
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamp('occurred_at');
            // The caller's own key for this use, so the same use reported twice counts once. Null for a use that has none.
            $table->string('idempotency_key', 100)->nullable();
            // The API token it was made with, if any. Not a foreign key: the use happened, whatever became of the token.
            $table->unsignedBigInteger('token_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'meter', 'idempotency_key'], 'usage_events_idempotency_unique');
            // What a customer has used this month, and what the report adds up.
            $table->index(['user_id', 'meter', 'occurred_at'], 'usage_events_user_meter_time_index');
            $table->index(['meter', 'occurred_at'], 'usage_events_meter_time_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_events');
    }
};
