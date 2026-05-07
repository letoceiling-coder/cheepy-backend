<?php

use App\Models\MailIntegration;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        MailIntegration::query()->firstOrCreate(
            ['name' => 'smtp'],
            ['is_active' => false, 'config' => []]
        );
    }

    public function down(): void
    {
        MailIntegration::query()->where('name', 'smtp')->delete();
    }
};
