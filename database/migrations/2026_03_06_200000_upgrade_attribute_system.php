<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ── 1. attribute_value_normalization ──────────────────────────────
        Schema::create('attribute_value_normalization', function (Blueprint $table) {
            $table->id();
            $table->string('attribute_key', 60);
            // raw token as it arrives after regex/keyword extraction (lowercased)
            $table->string('raw_value', 300);
            // canonical value to store in product_attributes
            $table->string('normalized_value', 300);
            $table->timestamps();
            $table->unique(['attribute_key', 'raw_value']);
            $table->index('attribute_key');
        });

        // ── 2. attribute_dictionary ────────────────────────────────────────
        // Whitelist of accepted values per attribute. Used for filter UI
        // and fuzzy-nearest-match when an extracted value is not in the list.
        Schema::create('attribute_dictionary', function (Blueprint $table) {
            $table->id();
            $table->string('attribute_key', 60);
            $table->string('value', 300);
            $table->unsignedSmallInt('sort_order')->default(100);
            $table->timestamps();
            $table->unique(['attribute_key', 'value']);
            $table->index(['attribute_key', 'sort_order']);
        });

        // ── 3. confidence column on product_attributes ─────────────────────
        Schema::table('product_attributes', function (Blueprint $table) {
            $table->float('confidence')->default(1.0)->after('attr_type');
        });

        // ── 4. indexes on product_attributes ──────────────────────────────
        Schema::table('product_attributes', function (Blueprint $table) {
            if (!$this->indexExists('product_attributes', 'idx_pa_attr_name')) {
                $table->index('attr_name', 'idx_pa_attr_name');
            }
            if (!$this->indexExists('product_attributes', 'idx_pa_attr_value')) {
                $table->index('attr_value', 'idx_pa_attr_value');
            }
            if (!$this->indexExists('product_attributes', 'idx_pa_name_value')) {
                $table->index(['attr_name', 'attr_value'], 'idx_pa_name_value');
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_attributes', function (Blueprint $table) {
            $table->dropColumn('confidence');
            try { $table->dropIndex('idx_pa_attr_name'); } catch (\Throwable) {}
            try { $table->dropIndex('idx_pa_attr_value'); } catch (\Throwable) {}
            try { $table->dropIndex('idx_pa_name_value'); } catch (\Throwable) {}
        });
        Schema::dropIfExists('attribute_dictionary');
        Schema::dropIfExists('attribute_value_normalization');
    }

    private function indexExists(string $table, string $index): bool
    {
        $sm = Schema::getConnection()->getDoctrineSchemaManager();
        $indexes = $sm->listTableIndexes($table);
        return array_key_exists($index, $indexes);
    }
};
