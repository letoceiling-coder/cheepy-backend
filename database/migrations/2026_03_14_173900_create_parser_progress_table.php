<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parser_progress', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('job_id')->nullable()->constrained('parser_jobs')->nullOnDelete();
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('processed_items')->default(0);
            $table->unsignedInteger('failed_items')->default(0);
            $table->string('current_url', 1000)->nullable();
            $table->timestamps();
            $table->index(['job_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parser_progress');
    }
};
