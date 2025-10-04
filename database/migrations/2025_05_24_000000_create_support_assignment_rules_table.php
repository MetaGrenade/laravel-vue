<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('support_assignment_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_category_id')
                ->nullable()
                ->constrained('support_ticket_categories')
                ->nullOnDelete();
            $table->enum('priority', ['low', 'medium', 'high'])->nullable();
            $table->foreignId('assigned_to')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_assignment_rules');
    }
};
