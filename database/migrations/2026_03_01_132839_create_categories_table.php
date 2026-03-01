<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('external_slug', 200)->unique()->nullable(); // slug с sadovodbaza.ru
            $table->string('name', 500);
            $table->string('slug', 200)->unique();
            $table->string('url', 500)->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->integer('sort_order')->default(0);
            $table->string('icon', 50)->nullable();

            // Настройки парсинга
            $table->boolean('enabled')->default(true);
            $table->boolean('linked_to_parser')->default(false); // включена для авто-парсинга
            $table->integer('parser_products_limit')->default(0);  // 0=все
            $table->integer('parser_max_pages')->default(0);       // 0=все страницы
            $table->integer('parser_depth_limit')->default(0);

            // Статистика
            $table->integer('products_count')->default(0);
            $table->unsignedBigInteger('subcategory_options_count')->default(0); // опций в select
            $table->timestamp('last_parsed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
