<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('search_query_aggregates', function (Blueprint $table) {
            $table->id();
            $table->string('term')->unique();
            $table->unsignedBigInteger('total_count')->default(0);
            $table->unsignedBigInteger('total_results')->default(0);
            $table->unsignedBigInteger('zero_result_count')->default(0);
            $table->timestamp('last_ran_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_query_aggregates');
    }
};
