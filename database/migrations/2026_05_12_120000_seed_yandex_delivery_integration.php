<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('delivery_integrations')->where('name', 'yandex_delivery')->exists()) {
            return;
        }

        DB::table('delivery_integrations')->insert([
            'name' => 'yandex_delivery',
            'is_active' => false,
            'config' => json_encode([
                'environment' => 'production',
                'api_modes' => 'express,other_day',
                'oauth_token' => '',
                'platform_station_id' => '',
                'other_day_tariff' => 'time_interval',
                'sender_fullname' => 'Москва, рынок Садовод',
                'sender_city' => 'Москва',
                'sender_address' => 'МКАД, 14-й км, 4',
                'sender_lat' => '55.6534',
                'sender_lng' => '37.7201',
            ], JSON_UNESCAPED_UNICODE),
            'last_successful_auth_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('delivery_integrations')->where('name', 'yandex_delivery')->delete();
    }
};
