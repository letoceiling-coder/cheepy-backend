<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

            $table->string('original_url', 500);  // https://sadovodbaza.ru/uploaded_files/..._img_big.jpg
            $table->string('medium_url', 500)->nullable(); // _img_medium.jpg вариант
            $table->string('local_path', 500)->nullable(); // storage/app/photos/...
            $table->string('local_medium_path', 500)->nullable();
            $table->string('cdn_url', 500)->nullable();    // публичный URL если загружен на CDN

            $table->string('hash', 64)->nullable();        // MD5 файла
            $table->string('mime_type', 50)->nullable();   // image/jpeg
            $table->unsignedInteger('file_size')->nullable(); // байты
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();

            $table->boolean('is_primary')->default(false);
            $table->integer('sort_order')->default(0);
            $table->string('download_status', 20)->default('pending'); // pending, done, failed, skipped

            $table->timestamps();

            $table->index(['product_id', 'sort_order']);
            $table->index('download_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_photos');
    }
};
