<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parser_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('parser_settings', 'excluded_seller_slugs')) {
                $table->json('excluded_seller_slugs')->nullable()->after('default_category_ids');
            }
        });
    }

    public function down(): void
    {
        Schema::table('parser_settings', function (Blueprint $table) {
            if (Schema::hasColumn('parser_settings', 'excluded_seller_slugs')) {
                $table->dropColumn('excluded_seller_slugs');
            }
        });
    }
};
