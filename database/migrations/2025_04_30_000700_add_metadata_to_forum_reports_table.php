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
        Schema::table('forum_thread_reports', function (Blueprint $table) {
            $table->string('reason_category', 100)->nullable()->after('reporter_id');
            $table->string('evidence_url', 2048)->nullable()->after('reason');
        });

        Schema::table('forum_post_reports', function (Blueprint $table) {
            $table->string('reason_category', 100)->nullable()->after('reporter_id');
            $table->string('evidence_url', 2048)->nullable()->after('reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('forum_thread_reports', function (Blueprint $table) {
            $table->dropColumn(['reason_category', 'evidence_url']);
        });

        Schema::table('forum_post_reports', function (Blueprint $table) {
            $table->dropColumn(['reason_category', 'evidence_url']);
        });
    }
};
