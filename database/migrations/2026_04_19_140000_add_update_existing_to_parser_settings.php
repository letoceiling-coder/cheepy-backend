<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('parser_settings')) {
            return;
        }

        Schema::table('parser_settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('parser_settings', 'update_existing')) {
                // true = full mode (parse and update existing); false = parse only new external_ids
                $table->boolean('update_existing')->default(true)->after('download_medium');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('parser_settings')) {
            return;
        }

        Schema::table('parser_settings', function (Blueprint $table): void {
            if (Schema::hasColumn('parser_settings', 'update_existing')) {
                $table->dropColumn('update_existing');
            }
        });
    }
};
