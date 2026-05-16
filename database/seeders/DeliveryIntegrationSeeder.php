<?php

namespace Database\Seeders;

use App\Models\DeliveryIntegration;
use Illuminate\Database\Seeder;

class DeliveryIntegrationSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['cdek', 'yandex_delivery', 'nova_poshta', 'dhl', 'russian_post'] as $name) {
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
                        'yandex_delivery' => [
                            'environment' => 'production',
                            'api_modes' => 'express,other_day',
                            'oauth_token' => '',
                            'platform_station_id' => '',
                            'other_day_tariff' => 'time_interval',
                            'sender_city' => 'Москва',
                            'sender_address' => 'МКАД, 14-й км, 4',
                            'sender_lat' => '55.6534',
                            'sender_lng' => '37.7201',
                        ],
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
