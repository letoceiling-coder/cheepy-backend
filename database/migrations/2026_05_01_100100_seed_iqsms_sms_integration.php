<?php

use App\Models\SmsIntegration;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        SmsIntegration::query()->firstOrCreate(
            ['name' => 'iqsms'],
            [
                'is_active' => false,
                'config' => [],
            ]
        );
    }

    public function down(): void
    {
        SmsIntegration::query()->where('name', 'iqsms')->delete();
    }
};
