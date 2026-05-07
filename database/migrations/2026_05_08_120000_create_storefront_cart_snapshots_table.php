<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_cart_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->json('items');
            $table->timestamp('last_abandon_email_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_cart_snapshots');
    }
};
