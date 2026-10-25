<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forum_prefixes', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->string('color', 16);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('forum_board_forum_prefix', function (Blueprint $table) {
            $table->foreignId('forum_board_id')->constrained()->cascadeOnDelete();
            $table->foreignId('forum_prefix_id')->constrained()->cascadeOnDelete();
            $table->primary(['forum_board_id', 'forum_prefix_id']);
        });

        Schema::table('forum_boards', function (Blueprint $table) {
            $table->boolean('prefixes_required')->default(false);
        });

        Schema::table('forum_threads', function (Blueprint $table) {
            $table->foreignId('forum_prefix_id')->nullable()->after('forum_board_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('forum_threads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('forum_prefix_id');
        });

        Schema::table('forum_boards', function (Blueprint $table) {
            $table->dropColumn('prefixes_required');
        });

        Schema::dropIfExists('forum_board_forum_prefix');
        Schema::dropIfExists('forum_prefixes');
    }
};
