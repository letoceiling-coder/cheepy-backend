<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_key_id')->constrained('saas_api_keys')->cascadeOnDelete();
            $table->string('endpoint', 255);
            $table->unsignedInteger('request_count')->default(1);
            $table->unsignedInteger('response_time')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['api_key_id', 'created_at']);
            $table->index('endpoint');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_usage_logs');
    }
};
