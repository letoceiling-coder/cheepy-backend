<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parser_progress', function (Blueprint $table): void {
            if (!Schema::hasColumn('parser_progress', 'speed_per_min')) {
                $table->decimal('speed_per_min', 10, 2)->default(0)->after('current_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('parser_progress', function (Blueprint $table): void {
            if (Schema::hasColumn('parser_progress', 'speed_per_min')) {
                $table->dropColumn('speed_per_min');
            }
        });
    }
};
