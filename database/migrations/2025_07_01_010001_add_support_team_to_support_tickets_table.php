<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            Schema::disableForeignKeyConstraints();

            Schema::rename('support_tickets', 'support_tickets_tmp');

            Schema::create('support_tickets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('subject');
                $table->text('body');
                $table->enum('status', ['open', 'pending', 'closed'])->default('pending');
                $table->enum('priority', ['low', 'medium', 'high'])->default('medium');
                $table->foreignId('support_ticket_category_id')
                    ->nullable()
                    ->constrained('support_ticket_categories')
                    ->nullOnDelete();
                $table->foreignId('assigned_to')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
                $table->foreignId('support_team_id')
                    ->nullable()
                    ->constrained('support_teams')
                    ->nullOnDelete();
                $table->timestamp('resolved_at')->nullable();
                $table->foreignId('resolved_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
                $table->unsignedTinyInteger('customer_satisfaction_rating')->nullable();
                $table->timestamps();
            });

            $records = DB::table('support_tickets_tmp')->orderBy('id')->get();

            foreach ($records as $record) {
                DB::table('support_tickets')->insert([
                    'id' => $record->id,
                    'user_id' => $record->user_id,
                    'subject' => $record->subject,
                    'body' => $record->body,
                    'status' => $record->status,
                    'priority' => $record->priority,
                    'assigned_to' => $record->assigned_to,
                    'support_team_id' => null,
                    'resolved_at' => $record->resolved_at,
                    'resolved_by' => $record->resolved_by,
                    'customer_satisfaction_rating' => $record->customer_satisfaction_rating,
                    'created_at' => $record->created_at,
                    'updated_at' => $record->updated_at,
                    'support_ticket_category_id' => $record->support_ticket_category_id,
                ]);
            }

            Schema::drop('support_tickets_tmp');

            Schema::enableForeignKeyConstraints();

            return;
        }

        Schema::table('support_tickets', function (Blueprint $table) {
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

        if ($driver === 'sqlite') {
            Schema::disableForeignKeyConstraints();

            Schema::rename('support_tickets', 'support_tickets_tmp');

            Schema::create('support_tickets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('subject');
                $table->text('body');
                $table->enum('status', ['open', 'pending', 'closed'])->default('pending');
                $table->enum('priority', ['low', 'medium', 'high'])->default('medium');
                $table->foreignId('support_ticket_category_id')
                    ->nullable()
                    ->constrained('support_ticket_categories')
                    ->nullOnDelete();
                $table->foreignId('assigned_to')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
                $table->timestamp('resolved_at')->nullable();
                $table->foreignId('resolved_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
                $table->unsignedTinyInteger('customer_satisfaction_rating')->nullable();
                $table->timestamps();
            });

            $records = DB::table('support_tickets_tmp')->orderBy('id')->get();

            foreach ($records as $record) {
                DB::table('support_tickets')->insert([
                    'id' => $record->id,
                    'user_id' => $record->user_id,
                    'subject' => $record->subject,
                    'body' => $record->body,
                    'status' => $record->status,
                    'priority' => $record->priority,
                    'assigned_to' => $record->assigned_to,
                    'resolved_at' => $record->resolved_at,
                    'resolved_by' => $record->resolved_by,
                    'customer_satisfaction_rating' => $record->customer_satisfaction_rating,
                    'created_at' => $record->created_at,
                    'updated_at' => $record->updated_at,
                    'support_ticket_category_id' => $record->support_ticket_category_id,
                ]);
            }

            Schema::drop('support_tickets_tmp');

            Schema::enableForeignKeyConstraints();

            return;
        }

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropForeign(['support_team_id']);
            $table->dropColumn('support_team_id');
        });
    }
};
