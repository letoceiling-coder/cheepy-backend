<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Extend system_products for filters, CRM, SaaS.
     */
    public function up(): void
    {
        Schema::table('system_products', function (Blueprint $table) {
            $table->foreignId('seller_id')->nullable()->after('status')->constrained('sellers')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->after('seller_id')->constrained('catalog_categories')->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->after('category_id')->constrained('brands')->nullOnDelete();

            $table->index('seller_id');
            $table->index('category_id');
            $table->index('brand_id');
        });
    }

    public function down(): void
    {
        Schema::table('system_products', function (Blueprint $table) {
            $table->dropForeign(['seller_id']);
            $table->dropForeign(['category_id']);
            $table->dropForeign(['brand_id']);
        });
    }
};
