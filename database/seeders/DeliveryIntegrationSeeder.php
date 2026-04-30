<?php

namespace Database\Seeders;

use App\Models\DeliveryIntegration;
use Illuminate\Database\Seeder;

class DeliveryIntegrationSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['cdek', 'nova_poshta', 'dhl'] as $name) {
            DeliveryIntegration::updateOrCreate(
                ['name' => $name],
                [
                    'is_active' => false,
                    'config' => $name === 'cdek'
                        ? ['environment' => \App\Services\Delivery\CdekOAuthService::ENV_PRODUCTION]
                        : [],
                ]
            );
        }
    }
}
