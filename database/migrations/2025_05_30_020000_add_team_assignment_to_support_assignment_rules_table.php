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

            Schema::rename('support_assignment_rules', 'support_assignment_rules_tmp');

            Schema::create('support_assignment_rules', function (Blueprint $table) {
                $table->id();
                $table->foreignId('support_ticket_category_id')
                    ->nullable()
                    ->constrained('support_ticket_categories')
                    ->nullOnDelete();
                $table->enum('priority', ['low', 'medium', 'high'])->nullable();
                $table->foreignId('assigned_to')
                    ->nullable()
                    ->constrained('users')
                    ->cascadeOnDelete();
                $table->string('assignee_type', 20)->default('user');
                $table->foreignId('support_team_id')
                    ->nullable()
                    ->constrained('support_teams')
                    ->nullOnDelete();
                $table->unsignedInteger('position')->default(0);
                $table->boolean('active')->default(true);
                $table->timestamps();
            });

            $rules = DB::table('support_assignment_rules_tmp')->orderBy('id')->get();

            foreach ($rules as $rule) {
                DB::table('support_assignment_rules')->insert([
                    'id' => $rule->id,
                    'support_ticket_category_id' => $rule->support_ticket_category_id,
                    'priority' => $rule->priority,
                    'assigned_to' => $rule->assigned_to,
                    'assignee_type' => 'user',
                    'support_team_id' => null,
                    'position' => $rule->position,
                    'active' => $rule->active,
                    'created_at' => $rule->created_at,
                    'updated_at' => $rule->updated_at,
                ]);
            }

            Schema::drop('support_assignment_rules_tmp');

            Schema::enableForeignKeyConstraints();

            return;
        }

        Schema::table('support_assignment_rules', function (Blueprint $table) {
            $table->string('assignee_type', 20)->default('user')->after('priority');
            $table->foreignId('support_team_id')
                ->nullable()
                ->after('assigned_to')
                ->constrained('support_teams')
                ->nullOnDelete();
        });

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE support_assignment_rules ALTER COLUMN assigned_to DROP NOT NULL');
        } else {
            DB::statement('ALTER TABLE support_assignment_rules MODIFY assigned_to BIGINT UNSIGNED NULL');
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            Schema::disableForeignKeyConstraints();

            Schema::rename('support_assignment_rules', 'support_assignment_rules_tmp');

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

            $rules = DB::table('support_assignment_rules_tmp')
                ->whereNotNull('assigned_to')
                ->orderBy('id')
                ->get();

            foreach ($rules as $rule) {
                DB::table('support_assignment_rules')->insert([
                    'id' => $rule->id,
                    'support_ticket_category_id' => $rule->support_ticket_category_id,
                    'priority' => $rule->priority,
                    'assigned_to' => $rule->assigned_to,
                    'position' => $rule->position,
                    'active' => $rule->active,
                    'created_at' => $rule->created_at,
                    'updated_at' => $rule->updated_at,
                ]);
            }

            Schema::drop('support_assignment_rules_tmp');

            Schema::enableForeignKeyConstraints();

            return;
        }

        Schema::table('support_assignment_rules', function (Blueprint $table) {
            $table->dropForeign(['support_team_id']);
            $table->dropColumn(['assignee_type', 'support_team_id']);
        });

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE support_assignment_rules ALTER COLUMN assigned_to SET NOT NULL');
        } else {
            DB::statement('ALTER TABLE support_assignment_rules MODIFY assigned_to BIGINT UNSIGNED NOT NULL');
        }
    }
};
