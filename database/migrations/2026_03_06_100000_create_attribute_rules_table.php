<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * Stores regex / keyword rules for extracting product attributes from raw text.
         * Rules are loaded by AttributeExtractionService and applied in priority order.
         */
        Schema::create('attribute_rules', function (Blueprint $table) {
            $table->id();

            // canonical attribute key: size, material, brand, country_of_origin, color, article, pack_quantity
            $table->string('attribute_key', 60);

            // human label shown in admin and filter UI
            $table->string('display_name', 120);

            // 'regex' or 'keyword'
            $table->string('rule_type', 20)->default('regex');

            // The pattern. For regex: PCRE without delimiters. For keyword: plain word.
            $table->string('pattern', 500);

            // Optional: normalise captured value via synonym table first
            $table->boolean('apply_synonyms')->default(true);

            // attr_type written to product_attributes: text, size, color, number
            $table->string('attr_type', 20)->default('text');

            // Lower number = runs first
            $table->unsignedSmallInt('priority')->default(100);

            $table->boolean('enabled')->default(true);

            $table->timestamps();

            $table->index(['attribute_key', 'enabled', 'priority']);
        });

        /**
         * Synonym table: normalize raw tokens to canonical values.
         * e.g. "cotton" -> "хлопок", "х/б" -> "хлопок", "spandex" -> "эластан"
         */
        Schema::create('attribute_synonyms', function (Blueprint $table) {
            $table->id();

            // which attribute this synonym belongs to (can be NULL = global)
            $table->string('attribute_key', 60)->nullable();

            // raw token as found in text (lowercase, trimmed)
            $table->string('word', 200);

            // canonical value to store
            $table->string('normalized_value', 200);

            $table->timestamps();

            $table->unique(['attribute_key', 'word']);
            $table->index('word');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_synonyms');
        Schema::dropIfExists('attribute_rules');
    }
};
