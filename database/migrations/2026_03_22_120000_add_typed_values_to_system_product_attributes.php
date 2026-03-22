<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Typed value columns for filters and range queries.
     * attr_value kept for display. value_int/value_float for typed queries.
     */
    public function up(): void
    {
        Schema::table('system_product_attributes', function (Blueprint $table) {
            $table->bigInteger('value_int')->nullable()->after('attr_type');
            $table->double('value_float')->nullable()->after('value_int');

            $table->index('value_int');
            $table->index('value_float');
        });
    }

    public function down(): void
    {
        Schema::table('system_product_attributes', function (Blueprint $table) {
            $table->dropIndex(['value_int']);
            $table->dropIndex(['value_float']);
            $table->dropColumn(['value_int', 'value_float']);
        });
    }
};
