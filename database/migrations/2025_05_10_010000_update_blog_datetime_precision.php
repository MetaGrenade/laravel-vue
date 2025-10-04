<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('blogs')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if (! Schema::hasColumn('blogs', 'published_at')) {
            return;
        }

        switch ($driver) {
            case 'mysql':
                DB::statement('ALTER TABLE blogs MODIFY published_at TIMESTAMP(6) NULL');
                if (Schema::hasColumn('blogs', 'scheduled_for')) {
                    DB::statement('ALTER TABLE blogs MODIFY scheduled_for TIMESTAMP(6) NULL');
                }
                break;
            case 'pgsql':
                DB::statement('ALTER TABLE blogs ALTER COLUMN published_at TYPE TIMESTAMP(6) WITHOUT TIME ZONE');
                if (Schema::hasColumn('blogs', 'scheduled_for')) {
                    DB::statement('ALTER TABLE blogs ALTER COLUMN scheduled_for TYPE TIMESTAMP(6) WITHOUT TIME ZONE');
                }
                break;
            case 'sqlsrv':
                DB::statement('ALTER TABLE blogs ALTER COLUMN published_at DATETIME2(6) NULL');
                if (Schema::hasColumn('blogs', 'scheduled_for')) {
                    DB::statement('ALTER TABLE blogs ALTER COLUMN scheduled_for DATETIME2(6) NULL');
                }
                break;
            default:
                // SQLite stores timestamps as text and already preserves precision.
                break;
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('blogs')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if (! Schema::hasColumn('blogs', 'published_at')) {
            return;
        }

        switch ($driver) {
            case 'mysql':
                DB::statement('ALTER TABLE blogs MODIFY published_at TIMESTAMP NULL');
                if (Schema::hasColumn('blogs', 'scheduled_for')) {
                    DB::statement('ALTER TABLE blogs MODIFY scheduled_for TIMESTAMP NULL');
                }
                break;
            case 'pgsql':
                DB::statement('ALTER TABLE blogs ALTER COLUMN published_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
                if (Schema::hasColumn('blogs', 'scheduled_for')) {
                    DB::statement('ALTER TABLE blogs ALTER COLUMN scheduled_for TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
                }
                break;
            case 'sqlsrv':
                DB::statement('ALTER TABLE blogs ALTER COLUMN published_at DATETIME2(0) NULL');
                if (Schema::hasColumn('blogs', 'scheduled_for')) {
                    DB::statement('ALTER TABLE blogs ALTER COLUMN scheduled_for DATETIME2(0) NULL');
                }
                break;
            default:
                break;
        }
    }
};
