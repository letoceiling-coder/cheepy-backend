<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Track when donor was last seen for sync awareness.
     * When products.updated_at > donor_updated_at → system_product marked needs_review.
     */
    public function up(): void
    {
        Schema::table('product_sources', function (Blueprint $table) {
            $table->timestamp('donor_updated_at')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('product_sources', function (Blueprint $table) {
            $table->dropColumn('donor_updated_at');
        });
    }
};
