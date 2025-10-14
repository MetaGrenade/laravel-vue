<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('support_assignment_rules', function (Blueprint $table) {
            $table->string('assignee_type', 20)->default('user')->after('priority');
            $table->foreignId('support_team_id')
                ->nullable()
                ->after('assigned_to')
                ->constrained('support_teams')
                ->nullOnDelete();
        });

        DB::statement('ALTER TABLE support_assignment_rules MODIFY assigned_to BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        Schema::table('support_assignment_rules', function (Blueprint $table) {
            $table->dropForeign(['support_team_id']);
            $table->dropColumn(['assignee_type', 'support_team_id']);
        });

        DB::statement('ALTER TABLE support_assignment_rules MODIFY assigned_to BIGINT UNSIGNED NOT NULL');
    }
};
