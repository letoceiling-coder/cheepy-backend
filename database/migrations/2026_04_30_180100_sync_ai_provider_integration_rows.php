<?php

use App\Models\AiProviderIntegration;
use App\Support\AiProviderCatalog;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        foreach (AiProviderCatalog::providerKeys() as $name) {
            AiProviderIntegration::query()->firstOrCreate(
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

    public function down(): void
    {
        // не удаляем строки — могут быть настройки пользователя
    }
};
