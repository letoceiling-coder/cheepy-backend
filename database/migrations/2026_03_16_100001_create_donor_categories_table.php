<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('donor_categories', function (Blueprint $table) {
            $table->id();
            $table->string('external_id')->unique();
            $table->string('name');
            $table->string('slug');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('source_url')->nullable();
            $table->boolean('parser_enabled')->default(true);
            $table->timestamps();

            $table->foreign('parent_id')->references('id')->on('donor_categories')->onDelete('cascade');
            $table->index('external_id');
            $table->index('slug');
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donor_categories');
    }
};
