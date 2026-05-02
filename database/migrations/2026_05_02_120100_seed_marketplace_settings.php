<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $rows = [
            ['key' => 'marketplace_name', 'value' => 'Cheepy', 'type' => 'string'],
            ['key' => 'support_emails', 'value' => json_encode([['email' => 'support@cheepy.ru', 'description' => 'Основная поддержка']], JSON_UNESCAPED_UNICODE), 'type' => 'json'],
            ['key' => 'support_phones', 'value' => json_encode([['phone' => '+7 (800) 123-45-67', 'description' => 'Основной телефон']], JSON_UNESCAPED_UNICODE), 'type' => 'json'],
            ['key' => 'default_currency', 'value' => 'RUB', 'type' => 'string'],
            ['key' => 'maintenance_enabled', 'value' => '0', 'type' => 'bool'],
            ['key' => 'maintenance_delay_minutes', 'value' => '10', 'type' => 'int'],
            ['key' => 'maintenance_started_at', 'value' => null, 'type' => 'string'],
            ['key' => 'seller_registration_enabled', 'value' => '1', 'type' => 'bool'],
            ['key' => 'default_commission_percent', 'value' => '10', 'type' => 'float'],
            ['key' => 'category_commissions', 'value' => '{}', 'type' => 'json'],
            ['key' => 'currency_rates', 'value' => json_encode([
                'date' => null,
                'base' => 'RUB',
                'rates' => [['code' => 'RUB', 'name' => 'Российский рубль', 'nominal' => 1, 'value' => 1]],
            ], JSON_UNESCAPED_UNICODE), 'type' => 'json'],
        ];

        foreach ($rows as $row) {
            DB::table('settings')->updateOrInsert(
                ['key' => $row['key']],
                ['group' => 'marketplace', 'value' => $row['value'], 'type' => $row['type'], 'updated_at' => $now, 'created_at' => $now]
            );
        }
    }

    public function down(): void
    {
        DB::table('settings')->where('group', 'marketplace')->delete();
    }
};
