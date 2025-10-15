<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::table('support_tickets', function (Blueprint $table) use ($driver) {
            if ($driver === 'sqlite') {
                $table->unsignedBigInteger('support_team_id')->nullable()->after('assigned_to');

                return;
            }

            $table->foreignId('support_team_id')
                ->nullable()
                ->after('assigned_to')
                ->constrained('support_teams')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::table('support_tickets', function (Blueprint $table) use ($driver) {
            if ($driver === 'sqlite') {
                $table->dropColumn('support_team_id');

                return;
            }

            $table->dropForeign(['support_team_id']);
            $table->dropColumn('support_team_id');
        });
    }
};
