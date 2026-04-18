<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Связи «похожие товары / другие цвета» с донора (sadovodbaza .similar_products).
     */
    public function up(): void
    {
        Schema::create('product_similar', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('related_external_id', 32);
            $table->foreignId('related_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'related_external_id']);
            $table->index('related_external_id');
            $table->index('related_product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_similar');
    }
};
