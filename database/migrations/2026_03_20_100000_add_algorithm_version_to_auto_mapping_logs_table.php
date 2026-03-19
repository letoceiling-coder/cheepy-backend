<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_mapping_logs', function (Blueprint $table) {
            $table->string('algorithm_version', 32)->default('v1')->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('auto_mapping_logs', function (Blueprint $table) {
            $table->dropColumn('algorithm_version');
        });
    }
};
