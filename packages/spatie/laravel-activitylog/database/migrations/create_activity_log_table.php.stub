<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('activitylog.table_name', 'activity_log'), function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->nullableMorphs('subject');
            $table->nullableMorphs('causer');
            $table->string('event')->nullable();
            $table->json('properties')->nullable();
            $table->uuid('batch_uuid')->nullable()->index();
            $table->timestamps();

            $table->index('log_name');
            $table->index('event');
            $table->index(['causer_type', 'causer_id']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('activitylog.table_name', 'activity_log'));
    }
};
