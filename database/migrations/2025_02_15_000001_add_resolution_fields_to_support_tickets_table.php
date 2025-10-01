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
            if (! Schema::hasColumn('support_tickets', 'resolved_at')) {
                $table->timestamp('resolved_at')->nullable()->after('updated_at');
            }

            if (! Schema::hasColumn('support_tickets', 'resolved_by')) {
                $table->foreignId('resolved_by')
                    ->nullable()
                    ->after('resolved_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('support_tickets', 'customer_satisfaction_rating')) {
                $table->unsignedTinyInteger('customer_satisfaction_rating')->nullable()->after('resolved_by');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('support_tickets')) {
            return;
        }

        Schema::table('support_tickets', function (Blueprint $table) {
            if (Schema::hasColumn('support_tickets', 'customer_satisfaction_rating')) {
                $table->dropColumn('customer_satisfaction_rating');
            }

            if (Schema::hasColumn('support_tickets', 'resolved_by')) {
                $table->dropConstrainedForeignId('resolved_by');
            }

            if (Schema::hasColumn('support_tickets', 'resolved_at')) {
                $table->dropColumn('resolved_at');
            }
        });
    }
};
