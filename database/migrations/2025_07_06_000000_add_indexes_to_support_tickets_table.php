<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('support_tickets')) {
            return;
        }

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->index(['support_ticket_category_id', 'status', 'priority'], 'support_tickets_category_status_priority_index');
            $table->index(['assigned_to', 'status', 'priority'], 'support_tickets_assignee_status_priority_index');
            $table->index(['status', 'priority'], 'support_tickets_status_priority_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('support_tickets')) {
            return;
        }

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropIndex('support_tickets_category_status_priority_index');
            $table->dropIndex('support_tickets_assignee_status_priority_index');
            $table->dropIndex('support_tickets_status_priority_index');
        });
    }
};
