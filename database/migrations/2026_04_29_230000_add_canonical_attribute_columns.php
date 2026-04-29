<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_attributes', function (Blueprint $table) {
            if (! Schema::hasColumn('product_attributes', 'attribute_key')) {
                $table->string('attribute_key', 60)->nullable()->after('category_id');
            }
            if (! Schema::hasColumn('product_attributes', 'attr_value_original')) {
                $table->string('attr_value_original', 500)->nullable()->after('attr_value');
            }
        });

        Schema::table('system_product_attributes', function (Blueprint $table) {
            if (! Schema::hasColumn('system_product_attributes', 'attribute_key')) {
                $table->string('attribute_key', 60)->nullable()->after('system_product_id');
            }
            if (! Schema::hasColumn('system_product_attributes', 'confidence')) {
                $table->float('confidence')->default(1.0)->after('attr_type');
            }
        });

        Schema::table('product_attributes', function (Blueprint $table) {
            try {
                $table->index(['attribute_key', 'attr_value'], 'pa_attribute_key_value_idx');
            } catch (\Throwable) {
                // Index may already exist on environments where migration was retried.
            }
        });

        Schema::table('system_product_attributes', function (Blueprint $table) {
            try {
                $table->index(['attribute_key', 'attr_value'], 'spa_attribute_key_value_idx');
            } catch (\Throwable) {
                // Index may already exist on environments where migration was retried.
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_attributes', function (Blueprint $table) {
            try {
                $table->dropIndex('pa_attribute_key_value_idx');
            } catch (\Throwable) {
            }
            if (Schema::hasColumn('product_attributes', 'attr_value_original')) {
                $table->dropColumn('attr_value_original');
            }
            if (Schema::hasColumn('product_attributes', 'attribute_key')) {
                $table->dropColumn('attribute_key');
            }
        });

        Schema::table('system_product_attributes', function (Blueprint $table) {
            try {
                $table->dropIndex('spa_attribute_key_value_idx');
            } catch (\Throwable) {
            }
            if (Schema::hasColumn('system_product_attributes', 'confidence')) {
                $table->dropColumn('confidence');
            }
            if (Schema::hasColumn('system_product_attributes', 'attribute_key')) {
                $table->dropColumn('attribute_key');
            }
        });
    }
};
