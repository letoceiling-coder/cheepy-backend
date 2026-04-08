<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parser_state', function (Blueprint $table) {
            $table->string('network_mode', 16)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('parser_state', function (Blueprint $table) {
            $table->dropColumn('network_mode');
        });
    }
};
