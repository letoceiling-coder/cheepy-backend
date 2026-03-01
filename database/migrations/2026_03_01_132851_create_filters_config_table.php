<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('filters_config', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();

            $table->string('attr_name', 200);   // "Цвет", "Размер", "Цена", "color"
            $table->string('display_name', 500); // название отображаемое пользователю
            $table->string('display_type', 20)->default('checkbox'); // checkbox, select, range, radio
            $table->integer('sort_order')->default(0);

            // Диапазон для range-фильтров
            $table->decimal('range_min', 10, 2)->nullable();
            $table->decimal('range_max', 10, 2)->nullable();

            // Предустановленные значения (JSON)
            $table->json('preset_values')->nullable(); // ["XS","S","M","L","XL"]

            $table->boolean('is_active')->default(true);
            $table->boolean('is_filterable')->default(true); // показывать в публичных фильтрах

            $table->timestamps();

            $table->unique(['category_id', 'attr_name']);
            $table->index(['category_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('filters_config');
    }
};
