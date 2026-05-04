<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $existing = DB::table('delivery_integrations')->where('name', 'russian_post')->first();
        DB::table('delivery_integrations')->updateOrInsert(
            ['name' => 'russian_post'],
            [
                'is_active' => false,
                'config' => json_encode([
                    'sender_postal_index' => '',
                    'access_token' => '',
                    'auth_login' => '',
                    'auth_password' => '',
                    'mail_type' => 'POSTAL_PARCEL',
                    'mail_category' => 'ORDINARY',
                    'payment_method' => 'CASHLESS',
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'updated_at' => $now,
                'created_at' => $existing?->created_at ?? $now,
            ]
        );
    }

    public function down(): void
    {
        DB::table('delivery_integrations')->where('name', 'russian_post')->delete();
    }
};
