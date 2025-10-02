<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('support_ticket_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->foreignId('support_ticket_category_id')
                ->nullable()
                ->after('priority')
                ->constrained('support_ticket_categories')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('support_ticket_category_id');
        });

        Schema::dropIfExists('support_ticket_categories');
    }
};
