<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_quality', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('donor_category_id');
            $table->unsignedBigInteger('predicted_catalog_category_id')->nullable();
            $table->string('algorithm_version', 32)->default('v1');
            $table->unsignedSmallInteger('predicted_confidence')->default(0);
            $table->timestamp('predicted_at')->useCurrent();
            $table->boolean('overridden')->default(false);
            $table->unsignedBigInteger('override_catalog_category_id')->nullable();
            $table->timestamp('overridden_at')->nullable();
            $table->timestamps();

            $table->index('donor_category_id');
            $table->index('algorithm_version');
            $table->index('predicted_at');
            $table->index('overridden');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_quality');
    }
};

