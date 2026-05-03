<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('delivery_integrations')->updateOrInsert(
            ['name' => 'yandex_maps'],
            [
                'is_active' => false,
                'config' => json_encode(['api_key' => null], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('delivery_integrations')->where('name', 'yandex_maps')->delete();
    }
};
