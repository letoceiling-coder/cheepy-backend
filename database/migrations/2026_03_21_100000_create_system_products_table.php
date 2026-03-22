<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * System products — editable layer for admin.
     * Parser writes ONLY to products (donor). Admin works with system_products.
     */
    public function up(): void
    {
        Schema::create('system_products', function (Blueprint $table) {
            $table->id();
            $table->string('name', 500);
            $table->text('description')->nullable();
            $table->string('price', 100)->nullable();
            $table->unsignedInteger('price_raw')->nullable();
            $table->string('status', 20)->default('draft'); // draft, pending, approved, published
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_products');
    }
};
