<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_product_attributes', function (Blueprint $table) {
            $table->unique(['system_product_id', 'attr_name', 'attr_value']);
        });
    }

    public function down(): void
    {
        Schema::table('system_product_attributes', function (Blueprint $table) {
            $table->dropUnique(['system_product_id', 'attr_name', 'attr_value']);
        });
    }
};
