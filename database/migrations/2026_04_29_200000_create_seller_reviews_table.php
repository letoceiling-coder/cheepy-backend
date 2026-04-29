<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('sellers')->cascadeOnDelete();
            $table->string('author_name', 80);
            $table->unsignedTinyInteger('rating');
            $table->text('body');
            $table->boolean('is_published')->default(true);
            $table->timestamps();

            $table->index(['seller_id', 'is_published', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_reviews');
    }
};
