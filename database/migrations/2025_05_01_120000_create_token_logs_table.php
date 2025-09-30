<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('token_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personal_access_token_id')
                ->constrained('personal_access_tokens')
                ->cascadeOnDelete();
            $table->string('token_name')->nullable();
            $table->string('route');
            $table->string('method', 16);
            $table->string('status', 32);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_summary')->nullable();
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('token_logs');
    }
};
