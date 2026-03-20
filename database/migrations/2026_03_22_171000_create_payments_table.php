<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_key_id')->constrained('saas_api_keys')->cascadeOnDelete();
            $table->decimal('amount', 14, 4);
            $table->string('status', 30)->default('pending');
            $table->string('stripe_id', 255)->nullable()->unique();
            $table->timestamps();
            $table->index(['api_key_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
