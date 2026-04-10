<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ручной порядок отображения карточки в списках/блоках (CRM + публичный каталог при sort_by=position).
 * Для режимов «по просмотрам / продажам / новинкам» используются отдельные поля/логика позже.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_products', function (Blueprint $table) {
            $table->unsignedInteger('list_position')->default(0)->after('brand_id');
            $table->index(['status', 'list_position']);
        });
    }

    public function down(): void
    {
        Schema::table('system_products', function (Blueprint $table) {
            $table->dropIndex(['status', 'list_position']);
            $table->dropColumn('list_position');
        });
    }
};
