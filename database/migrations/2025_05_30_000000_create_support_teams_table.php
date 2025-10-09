<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('support_teams', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('support_team_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_team_id')
                ->constrained('support_teams')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['support_team_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_team_user');
        Schema::dropIfExists('support_teams');
    }
};
