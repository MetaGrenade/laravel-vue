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
            $table->string('status', 20)->default('pending')->after('evidence_url');
            $table->timestamp('reviewed_at')->nullable()->after('status');
            $table->foreignId('reviewed_by')->nullable()->after('reviewed_at')->constrained('users')->nullOnDelete();

            $table->index(['status']);
            $table->index(['reviewed_at']);
        });

        Schema::table('forum_post_reports', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->after('evidence_url');
            $table->timestamp('reviewed_at')->nullable()->after('status');
            $table->foreignId('reviewed_by')->nullable()->after('reviewed_at')->constrained('users')->nullOnDelete();

            $table->index(['status']);
            $table->index(['reviewed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('forum_thread_reports', function (Blueprint $table) {
            $table->dropForeign(['reviewed_by']);
            $table->dropColumn(['status', 'reviewed_at', 'reviewed_by']);
        });

        Schema::table('forum_post_reports', function (Blueprint $table) {
            $table->dropForeign(['reviewed_by']);
            $table->dropColumn(['status', 'reviewed_at', 'reviewed_by']);
        });
    }
};
