<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Photos for system products.
     */
    public function up(): void
    {
        Schema::create('system_product_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('system_product_id')->constrained('system_products')->cascadeOnDelete();
            $table->string('url', 1000);
            $table->boolean('is_primary')->default(false);
            $table->integer('sort_order')->default(0);

            $table->timestamps();

            $table->index(['system_product_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_product_photos');
    }
};
