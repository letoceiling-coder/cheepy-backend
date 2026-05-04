<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['api_key_id']);
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE payments MODIFY api_key_id BIGINT UNSIGNED NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE payments ALTER COLUMN api_key_id DROP NOT NULL');
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->foreign('api_key_id')
                ->references('id')
                ->on('saas_api_keys')
                ->nullOnDelete();

            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $table->foreignId('customer_order_id')
                    ->nullable()
                    ->after('api_key_id')
                    ->constrained('customer_orders')
                    ->nullOnDelete();
            } else {
                $table->foreignId('customer_order_id')
                    ->nullable()
                    ->constrained('customer_orders')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['customer_order_id']);
            $table->dropColumn('customer_order_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['api_key_id']);
        });

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE payments MODIFY api_key_id BIGINT UNSIGNED NOT NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE payments ALTER COLUMN api_key_id SET NOT NULL');
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->foreign('api_key_id')
                ->references('id')
                ->on('saas_api_keys')
                ->cascadeOnDelete();
        });
    }
};
