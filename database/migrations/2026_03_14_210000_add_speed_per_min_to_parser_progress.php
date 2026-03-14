<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('parser_progress')) {
            return;
        }

        Schema::table('parser_progress', function (Blueprint $table): void {
            if (!Schema::hasColumn('parser_progress', 'speed_per_min')) {
                $table->integer('speed_per_min')->default(0)->after('current_url');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('parser_progress')) {
            return;
        }

        Schema::table('parser_progress', function (Blueprint $table): void {
            if (Schema::hasColumn('parser_progress', 'speed_per_min')) {
                $table->dropColumn('speed_per_min');
            }
        });
    }
};
