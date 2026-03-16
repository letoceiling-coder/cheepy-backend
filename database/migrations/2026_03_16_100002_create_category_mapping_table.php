<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_mapping', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('donor_category_id');
            $table->unsignedBigInteger('catalog_category_id');
            $table->unsignedTinyInteger('confidence')->default(100);
            $table->boolean('is_manual')->default(false);
            $table->timestamps();

            $table->foreign('donor_category_id')->references('id')->on('donor_categories')->onDelete('cascade');
            $table->foreign('catalog_category_id')->references('id')->on('catalog_categories')->onDelete('cascade');
            $table->unique('donor_category_id');
            $table->index('catalog_category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_mapping');
    }
};
