<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('support_response_templates', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->foreignId('support_ticket_category_id')
                ->nullable()
                ->constrained('support_ticket_categories')
                ->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('support_response_template_support_team', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_response_template_id');
            $table->foreignId('support_team_id');
            $table->timestamps();

            $table->unique(['support_response_template_id', 'support_team_id'], 'support_template_team_unique');

            $table->foreign('support_response_template_id', 'srt_team_template_fk')
                ->references('id')
                ->on('support_response_templates')
                ->cascadeOnDelete();
            $table->foreign('support_team_id', 'srt_team_team_fk')
                ->references('id')
                ->on('support_teams')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_response_template_support_team');
        Schema::dropIfExists('support_response_templates');
    }
};
