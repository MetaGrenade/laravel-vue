<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What moderators have done to a member: warnings, bans and the lifting of bans (docs/moderation.md). One row for each, never changed
 * afterwards, so a member's history is the rows with their id in the order they happened.
 *
 * - `reason` is what the member is told. `note` is for staff only and is never shown to the member.
 * - `actor_id` is who did it; null when the system did (a ban that ran out).
 * - `subject_*` is what it was about, when a moderator started from a report (a forum post, a thread, a blog comment). The label is a
 *   copy of its title or start, so the history still says what it was when the content has gone.
 * - `notified` is whether the member was emailed (a moderator may choose not to tell a spammer).
 *
 * The user's own row says where they stand now: `banned_until` is when a ban ends by itself (null: it does not) and `ban_reason` what
 * they are told when they try to sign in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('moderation_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 16);
            $table->text('reason')->nullable();
            $table->text('note')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->string('subject_type', 32)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label')->nullable();
            $table->boolean('notified')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['type', 'created_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dateTime('banned_until')->nullable()->after('banned_at');
            $table->text('ban_reason')->nullable()->after('banned_until');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['banned_until', 'ban_reason']);
        });

        Schema::dropIfExists('moderation_actions');
    }
};
