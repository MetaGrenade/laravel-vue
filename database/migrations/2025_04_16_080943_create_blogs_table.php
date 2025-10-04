<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBlogsTable extends Migration
{
    public function up()
    {
        Schema::create('blogs', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique(); // For SEO-friendly URLs
            $table->text('excerpt')->nullable();
            $table->longText('body');
            $table->unsignedBigInteger('user_id');  // Foreign key to users table
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->timestamp('published_at', 6)->nullable();
            $table->timestamps();

            // Optional: add a foreign key constraint if desired
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('blogs');
    }
}
