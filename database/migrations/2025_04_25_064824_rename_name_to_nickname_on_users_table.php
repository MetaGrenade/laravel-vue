<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Rename the column
            $table->renameColumn('name', 'nickname');
        });

        Schema::table('users', function (Blueprint $table) {
            // Change it to unique
            $table->string('nickname')->unique()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Drop the unique index first
            $table->dropUnique(['nickname']);
        });

        Schema::table('users', function (Blueprint $table) {
            // Rename back to name
            $table->renameColumn('nickname', 'name');
        });

        Schema::table('users', function (Blueprint $table) {
            // Remove uniqueness constraint
            $table->string('name')->change();
        });
    }
};
