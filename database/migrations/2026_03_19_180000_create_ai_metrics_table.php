<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_metrics', function (Blueprint $table): void {
            $table->id();
            $table->date('date')->unique();
            $table->unsignedInteger('total_predictions')->default(0);
            $table->unsignedInteger('overrides')->default(0);
            $table->float('avg_confidence')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_metrics');
    }
};

