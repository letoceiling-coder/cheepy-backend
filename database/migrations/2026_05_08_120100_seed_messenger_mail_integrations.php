<?php

use App\Models\MailIntegration;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['telegram', 'whatsapp', 'vk'] as $name) {
            MailIntegration::query()->firstOrCreate(
                ['name' => $name],
                ['is_active' => false, 'config' => []],
            );
        }
    }

    public function down(): void
    {
        MailIntegration::query()->whereIn('name', ['telegram', 'whatsapp', 'vk'])->delete();
    }
};
