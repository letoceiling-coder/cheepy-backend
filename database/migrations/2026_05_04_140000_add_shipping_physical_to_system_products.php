<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Габариты и вес для расчёта доставки в карточке товара и на чекауте.
 * Пока необязательные: при отсутствии используются defaults из config/delivery.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_products', function (Blueprint $table) {
            $table->unsignedInteger('shipping_weight_g')->nullable()->after('list_position');
            $table->unsignedSmallInteger('shipping_length_cm')->nullable()->after('shipping_weight_g');
            $table->unsignedSmallInteger('shipping_width_cm')->nullable()->after('shipping_length_cm');
            $table->unsignedSmallInteger('shipping_height_cm')->nullable()->after('shipping_width_cm');
        });
    }

    public function down(): void
    {
        Schema::table('system_products', function (Blueprint $table) {
            $table->dropColumn([
                'shipping_weight_g',
                'shipping_length_cm',
                'shipping_width_cm',
                'shipping_height_cm',
            ]);
        });
    }
};
