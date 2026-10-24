<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The admin audit log (see docs/audit-log.md): one row for each change somebody makes in the admin area, never changed afterwards.
 *
 * Who and what are kept as plain columns with the names they had at the time, and not as foreign keys: the log has to outlive the
 * accounts and records it is about, and say what they were called when it happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // Who did it. `actor_label` is what they were called then.
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_label')->nullable();

            // What was done: `users.ban`, from the route `acp.users.ban`, and how it went (succeeded, failed or denied).
            $table->string('event', 120);
            $table->string('outcome', 12);

            // What it was done to: the first record the address names, and who owns it (the account it belongs to).
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label')->nullable();
            $table->string('owner_type')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();

            // What changed (before and after) and everything else worth knowing about the request.
            $table->json('changes')->nullable();
            $table->json('meta')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();

            // Nothing is ever updated, so there is no updated_at.
            $table->dateTime('created_at');

            $table->index('created_at');
            $table->index(['actor_id', 'created_at']);
            $table->index(['event', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index(['owner_type', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
