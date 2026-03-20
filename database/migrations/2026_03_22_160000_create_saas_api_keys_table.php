<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saas_api_keys', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('api_key_hash', 64)->unique();
            $table->unsignedInteger('requests_per_minute')->default(60);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->index(['is_active', 'requests_per_minute']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saas_api_keys');
    }
};
