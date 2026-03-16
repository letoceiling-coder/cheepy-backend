<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('category_mapping', function (Blueprint $table) {
            $table->dropForeign(['donor_category_id']);
            $table->dropUnique(['donor_category_id']);
            $table->unique(['donor_category_id', 'catalog_category_id']);
            $table->foreign('donor_category_id')->references('id')->on('donor_categories')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('category_mapping', function (Blueprint $table) {
            $table->dropForeign(['donor_category_id']);
            $table->dropUnique(['donor_category_id', 'catalog_category_id']);
            $table->unique('donor_category_id');
            $table->foreign('donor_category_id')->references('id')->on('donor_categories')->onDelete('cascade');
        });
    }
};
