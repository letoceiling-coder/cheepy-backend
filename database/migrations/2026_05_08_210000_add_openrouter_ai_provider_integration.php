<?php

use App\Models\AiProviderIntegration;
use App\Support\AiProviderCatalog;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        AiProviderIntegration::query()->firstOrCreate(
            ['name' => 'openrouter'],
            [
                'is_active' => true,
                'config' => [
                    'default_model' => AiProviderCatalog::defaultModel('openrouter'),
                ],
            ]
        );
    }

    public function down(): void
    {
        AiProviderIntegration::query()->where('name', 'openrouter')->delete();
    }
};
