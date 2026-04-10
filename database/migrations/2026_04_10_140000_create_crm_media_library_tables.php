<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_media_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('crm_media_folders')->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('slug', 64)->unique();
            $table->boolean('is_system')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('crm_media_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('folder_id')->constrained('crm_media_folders')->cascadeOnDelete();
            $table->string('path', 1024);
            $table->string('original_name', 500);
            $table->string('mime_type', 191)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            /** When file is in Корзина — folder_id там; куда вернуть при восстановлении */
            $table->foreignId('restore_folder_id')->nullable()->constrained('crm_media_folders')->nullOnDelete();
            $table->timestamps();

            $table->index(['folder_id', 'original_name']);
        });

        $now = now();
        DB::table('crm_media_folders')->insert([
            'parent_id' => null,
            'name' => 'Корзина',
            'slug' => '__trash__',
            'is_system' => true,
            'sort_order' => 999999,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_media_files');
        Schema::dropIfExists('crm_media_folders');
    }
};
