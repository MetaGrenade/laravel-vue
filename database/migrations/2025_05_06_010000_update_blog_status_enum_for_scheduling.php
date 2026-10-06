<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE blogs MODIFY status ENUM('draft','scheduled','published','archived') DEFAULT 'draft'");

            return;
        }

        if ($driver === 'pgsql') {
            // Laravel's enum() is a varchar with an inline CHECK constraint on PostgreSQL.
            DB::statement('ALTER TABLE blogs DROP CONSTRAINT IF EXISTS blogs_status_check');
            DB::statement("ALTER TABLE blogs ADD CONSTRAINT blogs_status_check CHECK (status IN ('draft','scheduled','published','archived'))");

            return;
        }

        if ($driver === 'sqlite') {
            Schema::table('blogs', function (Blueprint $table) {
                $table->string('status_temp')->default('draft');
            });

            DB::statement('UPDATE blogs SET status_temp = status');

            Schema::table('blogs', function (Blueprint $table) {
                $table->dropColumn('status');
            });

            Schema::table('blogs', function (Blueprint $table) {
                $table->enum('status', ['draft', 'scheduled', 'published', 'archived'])->default('draft');
            });

            DB::statement('UPDATE blogs SET status = status_temp');

            Schema::table('blogs', function (Blueprint $table) {
                $table->dropColumn('status_temp');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE blogs MODIFY status ENUM('draft','published','archived') DEFAULT 'draft'");

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE blogs DROP CONSTRAINT IF EXISTS blogs_status_check');
            DB::statement("ALTER TABLE blogs ADD CONSTRAINT blogs_status_check CHECK (status IN ('draft','published','archived'))");

            return;
        }

        if ($driver === 'sqlite') {
            Schema::table('blogs', function (Blueprint $table) {
                $table->string('status_temp')->default('draft');
            });

            DB::statement('UPDATE blogs SET status_temp = status');

            Schema::table('blogs', function (Blueprint $table) {
                $table->dropColumn('status');
            });

            Schema::table('blogs', function (Blueprint $table) {
                $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            });

            DB::statement('UPDATE blogs SET status = status_temp');

            Schema::table('blogs', function (Blueprint $table) {
                $table->dropColumn('status_temp');
            });
        }
    }
};
