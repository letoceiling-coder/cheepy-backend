<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * Нормализованное хранение атрибутов: один ряд = один атрибут.
         * Позволяет строить фильтры через GROUP BY attr_name + distinct attr_value.
         */
        Schema::create('product_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();

            $table->string('attr_name', 200);   // "Цвет", "Размер", "Материал", "color" и т.д.
            $table->string('attr_value', 500);  // "красный", "XL", "хлопок"
            $table->string('attr_type', 20)->default('text'); // text, color, size, number

            $table->timestamps();

            $table->index(['category_id', 'attr_name']);
            $table->index(['product_id', 'attr_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_attributes');
    }
};
