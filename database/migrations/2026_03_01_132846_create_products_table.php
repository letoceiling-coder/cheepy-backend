<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('external_id', 50)->unique(); // числовой ID с донора /odejda/{id}
            $table->string('source_url', 500)->nullable(); // полный URL с донора

            // Основные поля
            $table->string('title', 500)->default('');
            $table->string('price', 100)->nullable();     // "900 ₽" (строка как на сайте)
            $table->unsignedInteger('price_raw')->nullable(); // цена в копейках (целое)
            $table->text('description')->nullable();

            // Связи
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('seller_id')->nullable()->constrained('sellers')->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();

            // Мульти-категории (продукт может быть в нескольких категориях)
            $table->json('category_slugs')->nullable(); // ["platya", "jenskie-platya"]

            // Характеристики (отдельные столбцы для поиска + JSON для остального)
            $table->string('color', 200)->nullable();       // цвет из characteristics
            $table->string('size_range', 200)->nullable();  // размерный ряд
            $table->json('characteristics')->nullable();    // все остальные характеристики

            // Источник (VK, etc)
            $table->string('source_link', 500)->nullable();  // vk.com/public... ссылка из описания
            $table->timestamp('source_published_at')->nullable(); // "Товар добавлен: 30.12.2025"

            // Цветовые варианты (связь /catalog/cat?color=29)
            $table->string('color_external_id', 50)->nullable(); // color=29 с донора

            // Статус и модерация
            $table->string('status', 20)->default('active'); // active, hidden, excluded, error, pending
            $table->boolean('is_relevant')->default(true);
            $table->timestamp('relevance_checked_at')->nullable();
            $table->text('parse_error')->nullable();

            // Фото (JSON array URLs) + флаг скачивания
            $table->json('photos')->nullable();           // оригинальные URL с донора
            $table->boolean('photos_downloaded')->default(false);
            $table->integer('photos_count')->default(0);

            // Временные метки парсинга
            $table->timestamp('parsed_at')->nullable();
            $table->timestamps();

            // Индексы для фильтрации
            $table->index('status');
            $table->index('category_id');
            $table->index('seller_id');
            $table->index('brand_id');
            $table->index('price_raw');
            $table->index('color');
            $table->index('parsed_at');
            $table->index('is_relevant');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
