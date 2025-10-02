<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'banned_at')) {
                $table->timestamp('banned_at')
                    ->nullable()
                    ->after('is_banned');
            }

            if (! Schema::hasColumn('users', 'banned_by_id')) {
                $table->foreignId('banned_by_id')
                    ->nullable()
                    ->after('banned_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'banned_by_id')) {
                $table->dropConstrainedForeignId('banned_by_id');
            }

            if (Schema::hasColumn('users', 'banned_at')) {
                $table->dropColumn('banned_at');
            }
        });
    }
};
