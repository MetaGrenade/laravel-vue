<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // whether the user is banned
            $table->boolean('is_banned')
                ->default(false)
                ->after('remember_token');

            // last time the user was active
            $table->timestamp('last_activity_at')
                ->nullable()
                ->after('is_banned');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['last_activity_at', 'is_banned']);
        });
    }
};
