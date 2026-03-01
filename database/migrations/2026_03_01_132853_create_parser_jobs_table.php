<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parser_jobs', function (Blueprint $table) {
            $table->id();

            // Тип задания
            $table->string('type', 50)->default('full'); // full, menu_only, category, product, seller

            // Параметры запуска
            $table->json('options')->nullable(); // все параметры: categories, limit, save_photos, etc.

            // Прогресс
            $table->string('status', 20)->default('pending'); // pending, running, completed, failed, cancelled
            $table->integer('total_categories')->default(0);
            $table->integer('parsed_categories')->default(0);
            $table->integer('total_products')->default(0);
            $table->integer('parsed_products')->default(0);
            $table->integer('saved_products')->default(0);
            $table->integer('errors_count')->default(0);
            $table->integer('photos_downloaded')->default(0);
            $table->integer('photos_failed')->default(0);
            $table->string('current_action', 500)->nullable(); // "Парсим категорию: платья (3/20)"

            // Пагинация
            $table->integer('current_page')->default(0);
            $table->integer('total_pages')->default(0);
            $table->string('current_category_slug', 200)->nullable();

            // Метаданные
            $table->unsignedBigInteger('pid')->nullable(); // PID процесса
            $table->string('log_file', 500)->nullable();  // путь к файлу лога

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parser_jobs');
    }
};
