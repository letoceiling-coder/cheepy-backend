<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parser_settings', function (Blueprint $table) {
            $table->json('proxy_urls')->nullable()->after('proxy_url');
        });

        if (Schema::hasTable('parser_settings')) {
            $rows = DB::table('parser_settings')->select('id', 'proxy_url')->get();
            foreach ($rows as $row) {
                $u = trim((string) ($row->proxy_url ?? ''));
                DB::table('parser_settings')->where('id', $row->id)->update([
                    'proxy_urls' => json_encode($u !== '' ? [$u] : []),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('parser_settings', function (Blueprint $table) {
            $table->dropColumn('proxy_urls');
        });
    }
};
