<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('faq_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('description')->nullable();
            $table->integer('order')->default(0);
            $table->timestamps();
        });

        Schema::table('faqs', function (Blueprint $table) {
            $table->foreignId('faq_category_id')
                ->nullable()
                ->after('id')
                ->constrained('faq_categories')
                ->cascadeOnDelete();
        });

        $now = now();
        $defaultCategoryId = DB::table('faq_categories')->insertGetId([
            'name' => 'General',
            'slug' => Str::slug('General'),
            'description' => 'General support questions and answers.',
            'order' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('faqs')->update(['faq_category_id' => $defaultCategoryId]);
    }

    public function down(): void
    {
        Schema::table('faqs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('faq_category_id');
        });

        Schema::dropIfExists('faq_categories');
    }
};
