<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('parser_settings')) {
            return;
        }

        DB::table('parser_settings')->update(['download_photos' => false]);

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE parser_settings MODIFY download_photos TINYINT(1) NOT NULL DEFAULT 0');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('parser_settings')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE parser_settings MODIFY download_photos TINYINT(1) NOT NULL DEFAULT 1');
        }
    }
};
