<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name', 500)->unique();
            $table->string('slug', 200)->unique();
            $table->string('logo_url', 500)->nullable();
            $table->string('logo_local_path', 500)->nullable(); // сохраненный локально
            $table->string('status', 20)->default('active');    // active, inactive

            // SEO
            $table->string('seo_title', 500)->nullable();
            $table->text('seo_description')->nullable();

            // Связь с категориями (JSON array of category IDs)
            $table->json('category_ids')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brands');
    }
};
