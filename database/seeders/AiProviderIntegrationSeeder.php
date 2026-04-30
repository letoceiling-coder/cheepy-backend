<?php

namespace Database\Seeders;

use App\Models\AiProviderIntegration;
use App\Support\AiProviderCatalog;
use Illuminate\Database\Seeder;

class AiProviderIntegrationSeeder extends Seeder
{
    public function run(): void
    {
        foreach (AiProviderCatalog::providerKeys() as $name) {
            AiProviderIntegration::updateOrCreate(
                ['name' => $name],
                [
                    'is_active' => false,
                    'config' => [
                        'default_model' => AiProviderCatalog::defaultModel($name),
                    ],
                ]
            );
        }
    }
}
