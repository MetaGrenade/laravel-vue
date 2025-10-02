<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forum_categories', function (Blueprint $table) {
            if (!Schema::hasColumn('forum_categories', 'access_permission')) {
                $table->string('access_permission')->nullable()->after('description');
            }

            if (!Schema::hasColumn('forum_categories', 'is_published')) {
                $table->boolean('is_published')->default(true)->after('access_permission');
            }
        });
    }

    public function down(): void
    {
        Schema::table('forum_categories', function (Blueprint $table) {
            if (Schema::hasColumn('forum_categories', 'is_published')) {
                $table->dropColumn('is_published');
            }

            if (Schema::hasColumn('forum_categories', 'access_permission')) {
                $table->dropColumn('access_permission');
            }
        });
    }
};
