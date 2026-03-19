<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_mapping_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('donor_category_id');
            $table->unsignedBigInteger('suggested_catalog_category_id')->nullable();
            $table->unsignedSmallInteger('confidence')->default(0);
            $table->string('decision', 32);
            $table->string('reason', 512)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('donor_category_id');
            $table->index('decision');
            $table->index('created_at');

            $table->foreign('donor_category_id')
                ->references('id')
                ->on('donor_categories')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_mapping_logs');
    }
};
