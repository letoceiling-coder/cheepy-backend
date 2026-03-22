<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_product_attributes', function (Blueprint $table) {
            $table->string('attr_value_original', 500)->nullable()->after('attr_value');
        });
    }

    public function down(): void
    {
        Schema::table('system_product_attributes', function (Blueprint $table) {
            $table->dropColumn('attr_value_original');
        });
    }
};
