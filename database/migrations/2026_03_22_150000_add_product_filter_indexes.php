<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * (attr_name, attr_value) already in create_system_product_attributes_table.
     */
    public function up(): void
    {
        Schema::table('system_products', function (Blueprint $table) {
            $table->index(['category_id', 'price_raw']);
        });

        Schema::table('system_product_attributes', function (Blueprint $table) {
            $table->index(['attr_name', 'value_int']);
            $table->index(['attr_name', 'value_float']);
        });
    }

    public function down(): void
    {
        Schema::table('system_products', function (Blueprint $table) {
            $table->dropIndex(['category_id', 'price_raw']);
        });

        Schema::table('system_product_attributes', function (Blueprint $table) {
            $table->dropIndex(['attr_name', 'value_int']);
            $table->dropIndex(['attr_name', 'value_float']);
        });
    }
};
