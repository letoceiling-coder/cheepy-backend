<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'account_role')) {
                $table->string('account_role', 32)->default('customer')->after('phone');
            }
            if (! Schema::hasColumn('users', 'seller_status')) {
                $table->string('seller_status', 32)->nullable()->after('account_role');
            }
            if (! Schema::hasColumn('users', 'seller_requested_at')) {
                $table->timestamp('seller_requested_at')->nullable()->after('seller_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['seller_requested_at', 'seller_status', 'account_role'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
