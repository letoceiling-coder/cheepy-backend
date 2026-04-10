<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('constructor_layout_templates', function (Blueprint $table) {
            $table->id();
            $table->string('template_key', 128)->unique();
            $table->string('name', 255);
            $table->string('description', 500)->nullable();
            $table->boolean('is_system')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('constructor_layout_template_blocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('constructor_layout_template_id');
            $table->foreign('constructor_layout_template_id', 'fk_cl_blocks_template_id')
                ->references('id')
                ->on('constructor_layout_templates')
                ->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('block_type', 120);
            $table->json('settings')->nullable();
            $table->string('client_key', 64)->nullable();
            $table->boolean('is_visible')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('constructor_layout_template_blocks');
        Schema::dropIfExists('constructor_layout_templates');
    }
};
