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

        // The older status set has no 'scheduled' value. Posts that were waiting
        // to be published go back to drafts first; otherwise restoring the enum
        // (MySQL strict mode) or the column (SQLite) fails as soon as any post
        // has used the status. PostgreSQL is handled by
        // 2026_10_08_000100_allow_scheduled_blog_status_on_postgresql.
        DB::table('blogs')->where('status', 'scheduled')->update(['status' => 'draft']);

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE blogs MODIFY status ENUM('draft','published','archived') DEFAULT 'draft'");

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
