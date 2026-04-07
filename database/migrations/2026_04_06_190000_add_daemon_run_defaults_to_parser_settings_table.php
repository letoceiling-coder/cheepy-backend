<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parser_settings', function (Blueprint $table) {
            $table->unsignedInteger('default_max_pages')->default(0)->after('queue_threshold');
            $table->unsignedInteger('default_products_per_category')->default(0)->after('default_max_pages');
            $table->boolean('default_linked_only')->default(false)->after('default_products_per_category');
            $table->json('default_category_ids')->nullable()->after('default_linked_only');
            $table->boolean('default_no_details')->default(false)->after('default_category_ids');
        });
    }

    public function down(): void
    {
        Schema::table('parser_settings', function (Blueprint $table) {
            $table->dropColumn([
                'default_max_pages',
                'default_products_per_category',
                'default_linked_only',
                'default_category_ids',
                'default_no_details',
            ]);
        });
    }
};
