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
            $table->foreignId('support_team_id')
                ->nullable()
                ->constrained('support_teams')
                ->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_response_templates');
    }
};
