<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parser_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->nullable()->constrained('parser_jobs')->nullOnDelete();

            $table->string('level', 20)->default('info'); // info, warn, error, debug
            $table->string('module', 50)->default('Parser'); // Parser, AI, System, Scheduler
            $table->string('message', 1000);
            $table->json('context')->nullable(); // дополнительные данные

            $table->string('entity_type', 50)->nullable();  // product, category, seller
            $table->string('entity_id', 100)->nullable();   // external_id

            $table->timestamp('logged_at')->useCurrent();

            $table->index(['level', 'logged_at']);
            $table->index(['module', 'logged_at']);
            $table->index('job_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parser_logs');
    }
};
