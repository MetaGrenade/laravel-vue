<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_notification_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('user_notification_settings', 'daily_digest')) {
                $table->boolean('daily_digest')->default(false)->after('team_notifications');
            }
        });
    }

    public function down(): void
    {
        Schema::table('user_notification_settings', function (Blueprint $table) {
            if (Schema::hasColumn('user_notification_settings', 'daily_digest')) {
                $table->dropColumn('daily_digest');
            }
        });
    }
};
