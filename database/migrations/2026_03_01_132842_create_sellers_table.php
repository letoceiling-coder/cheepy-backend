<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sellers', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 300)->unique();  // /s/slug с донора
            $table->string('name', 500);
            $table->string('source_url', 500)->nullable();  // https://sadovodbaza.ru/s/...

            // Местоположение
            $table->string('pavilion', 300)->nullable();        // "Корпус A 9-39", "13-53"
            $table->string('pavilion_line', 50)->nullable();    // "13 линия"
            $table->string('pavilion_number', 50)->nullable();  // "53 павильон"

            // Описание
            $table->text('description')->nullable();

            // Контакты
            $table->string('phone', 50)->nullable();
            $table->string('whatsapp_url', 500)->nullable();    // /posts/link?...whatsapp...
            $table->string('whatsapp_number', 50)->nullable();  // +79xxxxxxxxx
            $table->string('telegram_url', 500)->nullable();
            $table->string('vk_url', 500)->nullable();          // vk.com/...

            // Внутренний shop ID с донора (/posts/link?utm_content=shop{id})
            $table->string('external_shop_id', 50)->nullable();

            // Рейтинг и статус
            $table->string('status', 20)->default('active'); // active, hidden, blocked
            $table->boolean('is_verified')->default(false);
            $table->decimal('rating', 3, 2)->default(0);
            $table->integer('products_count')->default(0);

            // Категории которые продаёт (JSON)
            $table->json('seller_categories')->nullable(); // ["1000 мелочей", "Платья", ...]

            $table->timestamp('last_parsed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sellers');
    }
};
