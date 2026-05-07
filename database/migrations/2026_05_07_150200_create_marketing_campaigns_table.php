<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->string('channel_key', 32)->default('email');
            $table->string('audience', 32)->default('all');
            $table->string('status', 24)->default('draft');
            $table->string('subject', 255)->nullable();
            $table->mediumText('body_html')->nullable();
            $table->foreignId('marketing_email_template_id')
                ->nullable()
                ->constrained('marketing_email_templates')
                ->nullOnDelete();
            $table->timestamp('scheduled_at')->nullable();
            $table->json('metrics')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_campaigns');
    }
};
