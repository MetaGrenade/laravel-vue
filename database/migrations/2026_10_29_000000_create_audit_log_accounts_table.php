<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The accounts an audit log entry concerns (see docs/audit-log.md), so that the log of one account shows every entry that touched it.
 *
 * An entry used to have one owner (`owner_type`, `owner_id`), the first account it touched. A bulk action touches many, and the log of
 * every account but the first left it out. An entry now has a row here for each account it concerns, however many records it kept the
 * detail of, and the two columns are gone.
 *
 * The rows go with their entry (the foreign key cascades), so the daily prune needs no second step. They do not point at the accounts
 * themselves: like the rest of the log, they outlive them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log_accounts', function (Blueprint $table) {
            $table->foreignId('audit_log_id')->constrained('audit_logs')->cascadeOnDelete();
            $table->string('owner_type');
            $table->unsignedBigInteger('owner_id');

            // One row for an account an entry concerns. Also what the foreign key uses, since it starts with the entry.
            $table->unique(['audit_log_id', 'owner_type', 'owner_id']);
            // What the log of one account looks for.
            $table->index(['owner_type', 'owner_id']);
        });

        // Entries written while there was one owner each keep it.
        DB::table('audit_logs')
            ->whereNotNull('owner_type')
            ->whereNotNull('owner_id')
            ->chunkById(500, function ($logs) {
                DB::table('audit_log_accounts')->insert(
                    $logs->map(fn ($log) => ['audit_log_id' => $log->id, 'owner_type' => $log->owner_type, 'owner_id' => $log->owner_id])->all(),
                );
            });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['owner_type', 'owner_id']);
            $table->dropColumn(['owner_type', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('owner_type')->nullable()->after('subject_label');
            $table->unsignedBigInteger('owner_id')->nullable()->after('owner_type');
        });

        // Back to one owner: the first account of each entry (the others cannot be kept).
        $seen = [];

        DB::table('audit_log_accounts')->orderBy('audit_log_id')->orderBy('owner_type')->orderBy('owner_id')->each(function ($account) use (&$seen) {
            if (isset($seen[$account->audit_log_id])) {
                return;
            }

            $seen[$account->audit_log_id] = true;

            DB::table('audit_logs')->where('id', $account->audit_log_id)->update(['owner_type' => $account->owner_type, 'owner_id' => $account->owner_id]);
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index(['owner_type', 'owner_id']);
        });

        Schema::dropIfExists('audit_log_accounts');
    }
};
