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
            // Скачивать ли medium-версию фото отдельным HTTP-запросом.
            // По умолчанию выключено: иначе на каждое фото уходил лишний запрос к донору.
            if (! Schema::hasColumn('parser_settings', 'download_medium')) {
                $table->boolean('download_medium')->default(false)->after('store_photo_links');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('parser_settings')) {
            return;
        }

        Schema::table('parser_settings', function (Blueprint $table): void {
            if (Schema::hasColumn('parser_settings', 'download_medium')) {
                $table->dropColumn('download_medium');
            }
        });
    }
};
