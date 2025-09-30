<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forum_posts', function (Blueprint $table) {
            if (!Schema::hasColumn('forum_posts', 'deleted_at')) {
                $table->softDeletes()->after('edited_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('forum_posts', function (Blueprint $table) {
            if (Schema::hasColumn('forum_posts', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });
    }
};
