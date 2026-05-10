<?php

use App\Models\AiProviderIntegration;
use Illuminate\Database\Migrations\Migration;

/**
 * Replicate исключён из каталога CRM (Интеграции → ИИ); строка интеграции больше не используется.
 */
return new class extends Migration
{
    public function up(): void
    {
        AiProviderIntegration::query()->where('name', 'replicate')->delete();
    }

    public function down(): void
    {
        // намеренно не восстанавливаем — провайдер убран из AiProviderCatalog
    }
};
