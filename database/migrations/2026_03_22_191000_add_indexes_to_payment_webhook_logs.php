<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_webhook_logs', function (Blueprint $table) {
            $table->index(['provider', 'provider_event_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('payment_webhook_logs', function (Blueprint $table) {
            $table->dropIndex(['provider', 'provider_event_id']);
            $table->dropIndex(['status']);
        });
    }
};
