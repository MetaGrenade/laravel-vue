<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'reputation_points')) {
                $table->unsignedInteger('reputation_points')->default(0)->after('forum_signature');
            }
        });

        Schema::create('badges', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('description')->nullable();
            $table->unsignedInteger('points_required')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('badge_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('badge_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('awarded_at')->nullable();
            $table->timestamps();
            $table->unique(['badge_id', 'user_id']);
        });

        Schema::create('reputation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event');
            $table->integer('points');
            $table->nullableMorphs('source');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reputation_events');
        Schema::dropIfExists('badge_user');
        Schema::dropIfExists('badges');

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'reputation_points')) {
                $table->dropColumn('reputation_points');
            }
        });
    }
};
