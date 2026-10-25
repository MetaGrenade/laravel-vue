<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a "take down what they posted" did (docs/moderation.md): how many threads, replies, comments and reviews, so the member's history
 * says more than "content was removed". It is read by key, never by the order of the keys (MySQL stores a JSON object's keys in an
 * order of its own).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('moderation_actions', function (Blueprint $table) {
            $table->json('details')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('moderation_actions', function (Blueprint $table) {
            $table->dropColumn('details');
        });
    }
};
