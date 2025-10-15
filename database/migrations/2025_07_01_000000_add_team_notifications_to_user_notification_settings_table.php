<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_notification_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('user_notification_settings', 'team_notifications')) {
                $table->boolean('team_notifications')->default(true)->after('channel_database');
            }
        });
    }

    public function down(): void
    {
        Schema::table('user_notification_settings', function (Blueprint $table) {
            if (Schema::hasColumn('user_notification_settings', 'team_notifications')) {
                $table->dropColumn('team_notifications');
            }
        });
    }
};
