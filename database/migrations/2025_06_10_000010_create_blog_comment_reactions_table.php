<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_comment_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_comment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reaction', 16);
            $table->timestamps();

            $table->unique(['blog_comment_id', 'user_id']);
            $table->index('reaction');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_comment_reactions');
    }
};
