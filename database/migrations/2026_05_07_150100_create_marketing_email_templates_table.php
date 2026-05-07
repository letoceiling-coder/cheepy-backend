<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('title', 160);
            $table->string('send_trigger', 48)->comment('registration|order_created|cart_abandon|promotions|preference_new_products|manual');
            $table->string('subject', 255);
            $table->mediumText('body_html');
            $table->boolean('is_automatic')->default(false)->comment('Если да — отправляется по событию при включённом SMTP и активном шаблоне');
            $table->boolean('is_active')->default(true);
            $table->text('placeholder_hint')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_email_templates');
    }
};
