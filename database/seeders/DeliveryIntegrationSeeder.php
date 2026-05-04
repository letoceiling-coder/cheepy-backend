<?php

namespace Database\Seeders;

use App\Models\DeliveryIntegration;
use Illuminate\Database\Seeder;

class DeliveryIntegrationSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['cdek', 'nova_poshta', 'dhl', 'russian_post'] as $name) {
            DeliveryIntegration::updateOrCreate(
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
