<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automatic moderation of the forum (docs/automod.md): the rules an administrator sets, and the "held" state of a thread or reply that a
 * rule has caught and a moderator has yet to look at.
 *
 * - `automod_rules.settings` holds what the type of rule needs (phrases, a number of links, a number of posts and minutes).
 * - `action` is what happens to a post that breaks it: `hold` (kept out of sight until a moderator approves it) or `reject` (refused).
 * - `only_new_days` limits a rule to members whose account is younger than that (null: everybody who is not staff).
 * - `held_at` is when a thread or reply was caught; null for everything that is out in the open. `held_reason` says which rule and why,
 *   in words, and is kept when the rule is deleted (`held_by_rule_id` then goes null).
 *
 * The two tables are written out one after the other, and not in a loop, because the tools that read the schema from the migrations
 * (static analysis) can only follow a table named in the code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automod_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('type', 16);
            $table->json('settings');
            $table->string('action', 8)->default('hold');
            $table->unsignedSmallInteger('only_new_days')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->unsignedInteger('hits')->default(0);
            $table->dateTime('last_hit_at')->nullable();
            $table->timestamps();
        });

        Schema::table('forum_posts', function (Blueprint $table) {
            $table->dateTime('held_at')->nullable()->index();
            $table->string('held_reason')->nullable();
            $table->foreignId('held_by_rule_id')->nullable()->constrained('automod_rules')->nullOnDelete();
        });

        Schema::table('forum_threads', function (Blueprint $table) {
            $table->dateTime('held_at')->nullable()->index();
            $table->string('held_reason')->nullable();
            $table->foreignId('held_by_rule_id')->nullable()->constrained('automod_rules')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('forum_threads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('held_by_rule_id');
            $table->dropIndex(['held_at']);
            $table->dropColumn(['held_at', 'held_reason']);
        });

        Schema::table('forum_posts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('held_by_rule_id');
            $table->dropIndex(['held_at']);
            $table->dropColumn(['held_at', 'held_reason']);
        });

        Schema::dropIfExists('automod_rules');
    }
};
