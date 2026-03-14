<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parser_settings', function (Blueprint $table): void {
            if (!Schema::hasColumn('parser_settings', 'workers_parser')) {
                $table->unsignedInteger('workers_parser')->default(2)->after('timeout_seconds');
            }
            if (!Schema::hasColumn('parser_settings', 'workers_photos')) {
                $table->unsignedInteger('workers_photos')->default(1)->after('workers_parser');
            }
            if (!Schema::hasColumn('parser_settings', 'proxy_enabled')) {
                $table->boolean('proxy_enabled')->default(false)->after('workers_photos');
            }
            if (!Schema::hasColumn('parser_settings', 'proxy_url')) {
                $table->string('proxy_url', 255)->nullable()->after('proxy_enabled');
            }
            if (!Schema::hasColumn('parser_settings', 'queue_threshold')) {
                $table->unsignedInteger('queue_threshold')->default(10000)->after('proxy_url');
            }
        });

        DB::table('parser_settings')->update([
            'workers_parser' => 2,
            'workers_photos' => 1,
            'proxy_enabled' => true,
            'proxy_url' => 'http://89.169.39.244:3128',
            'queue_threshold' => 10000,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('parser_settings', function (Blueprint $table): void {
            foreach (['workers_parser', 'workers_photos', 'proxy_enabled', 'proxy_url', 'queue_threshold'] as $col) {
                if (Schema::hasColumn('parser_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
