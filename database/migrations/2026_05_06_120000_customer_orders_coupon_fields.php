<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_orders', function (Blueprint $table) {
            $table->foreignId('coupon_id')
                ->nullable()
                ->after('user_id')
                ->constrained('coupons')
                ->nullOnDelete();
            $table->json('coupon_snapshot')->nullable()->after('discount_amount');
        });
    }

    public function down(): void
    {
        Schema::table('customer_orders', function (Blueprint $table) {
            $table->dropForeign(['coupon_id']);
            $table->dropColumn(['coupon_id', 'coupon_snapshot']);
        });
    }
};
