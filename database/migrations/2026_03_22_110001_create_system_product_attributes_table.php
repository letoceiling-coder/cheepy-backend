<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Relational attributes for system products. Ready for filters.
     */
    public function up(): void
    {
        Schema::create('system_product_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('system_product_id')->constrained('system_products')->cascadeOnDelete();
            $table->string('attr_name', 200);
            $table->string('attr_value', 500);
            $table->string('attr_type', 20)->default('text'); // text, int, float

            $table->timestamps();

            $table->index(['attr_name', 'attr_value']);
            $table->index('system_product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_product_attributes');
    }
};
