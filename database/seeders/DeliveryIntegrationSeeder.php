<?php

namespace Database\Seeders;

use App\Models\DeliveryIntegration;
use Illuminate\Database\Seeder;

class DeliveryIntegrationSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['cdek', 'nova_poshta', 'dhl', 'russian_post'] as $name) {
            /*
             * firstOrCreate: только строки создание — не перетирать config из CRM (ключи СДЭК и т.д.).
             * Раньше updateOrCreate сбрасывал client_id/client_secret при любом повторном db:seed на деплое.
             */
            DeliveryIntegration::firstOrCreate(
                ['name' => $name],
                [
                    'is_active' => false,
                    'config' => match ($name) {
                        'cdek' => ['environment' => \App\Services\Delivery\CdekOAuthService::ENV_PRODUCTION],
                        'russian_post' => [
                            'sender_postal_index' => '',
                            'access_token' => '',
                            'auth_login' => '',
                            'auth_password' => '',
                            'mail_type' => 'POSTAL_PARCEL',
                            'mail_category' => 'ORDINARY',
                            'payment_method' => 'CASHLESS',
                        ],
                        default => [],
                    },
                ]
            );
        }
    }
}
