<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parser_stats', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->unsignedInteger('products_parsed')->default(0);
            $table->unsignedInteger('errors_count')->default(0);
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->unsignedInteger('categories_parsed')->default(0);
            $table->unsignedInteger('jobs_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parser_stats');
    }
};
