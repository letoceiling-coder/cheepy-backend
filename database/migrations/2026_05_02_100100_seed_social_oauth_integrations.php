<?php

use App\Models\SocialOauthIntegration;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['vk', 'yandex', 'ok'] as $name) {
            SocialOauthIntegration::query()->firstOrCreate(
                ['name' => $name],
                ['is_active' => false, 'config' => []]
            );
        }
    }

    public function down(): void
    {
        SocialOauthIntegration::query()->whereIn('name', ['vk', 'yandex', 'ok'])->delete();
    }
};
