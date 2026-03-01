<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('excluded_rules', function (Blueprint $table) {
            $table->id();
            $table->string('pattern', 500);          // само слово/фраза/regex
            $table->string('type', 20)->default('word'); // word, phrase, regex

            // Действие при срабатывании
            $table->string('action', 20)->default('hide'); // delete, replace, hide, flag

            // Замена (если action=replace)
            $table->string('replacement', 500)->nullable();

            // Область применения
            $table->string('scope', 20)->default('global'); // global, category, product_type, temporary

            // Привязка к категории (если scope=category)
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();

            // Привязка к конкретному типу (если scope=product_type)
            $table->string('product_type', 200)->nullable();

            // Поля в которых проверять (title, description, characteristics)
            $table->json('apply_to_fields')->nullable(); // ["title", "description"]

            // Временные правила
            $table->timestamp('expires_at')->nullable();

            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0); // порядок применения
            $table->string('comment', 500)->nullable();

            $table->timestamps();

            $table->index('is_active');
            $table->index('scope');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('excluded_rules');
    }
};
