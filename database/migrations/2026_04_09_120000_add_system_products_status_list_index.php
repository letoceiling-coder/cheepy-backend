<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ускорение списков CRM: фильтр по status + сортировка по дате.
     */
    public function up(): void
    {
        Schema::table('system_products', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'system_products_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('system_products', function (Blueprint $table) {
            $table->dropIndex('system_products_status_created_idx');
        });
    }
};
