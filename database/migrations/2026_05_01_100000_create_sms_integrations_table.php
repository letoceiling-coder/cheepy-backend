<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_integrations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->unique();
            $table->boolean('is_active')->default(false);
            $table->json('config')->nullable();
            $table->timestamp('last_successful_auth_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_integrations');
    }
};
