<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_oauth_integrations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 32)->unique();
            $table->boolean('is_active')->default(false);
            $table->json('config')->nullable();
            $table->timestamp('last_successful_oauth_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_oauth_integrations');
    }
};
