<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Links system_products to donor products (products.id).
     * source = 'parser' — from sadovodbaza.ru parser.
     */
    public function up(): void
    {
        Schema::create('product_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('system_product_id')->constrained('system_products')->cascadeOnDelete();
            $table->unsignedBigInteger('donor_product_id');
            $table->string('source', 50)->default('parser');
            $table->timestamps();

            $table->foreign('donor_product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->unique(['system_product_id', 'donor_product_id', 'source']);
            $table->index('donor_product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_sources');
    }
};
