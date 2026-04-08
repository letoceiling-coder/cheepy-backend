<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_metrics', function (Blueprint $table): void {
            $table->string('algorithm_version', 32)->default('v1')->after('date');
            $table->dropUnique(['date']);
            $table->unique(['date', 'algorithm_version'], 'ai_metrics_date_version_unique');
            $table->index('algorithm_version');
        });
    }

    public function down(): void
    {
        Schema::table('ai_metrics', function (Blueprint $table): void {
            $table->dropUnique('ai_metrics_date_version_unique');
            $table->dropIndex(['algorithm_version']);
            $table->dropColumn('algorithm_version');
            $table->unique('date');
        });
    }
};

