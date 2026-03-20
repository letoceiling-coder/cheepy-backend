<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saas_api_keys', function (Blueprint $table) {
            $table->decimal('balance', 14, 4)->default(0)->after('requests_per_minute');
            $table->decimal('cost_per_request', 12, 6)->default(0.01)->after('balance');
        });
    }

    public function down(): void
    {
        Schema::table('saas_api_keys', function (Blueprint $table) {
            $table->dropColumn(['balance', 'cost_per_request']);
        });
    }
};
