<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parser_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('download_photos')->default(true);
            $table->boolean('store_photo_links')->default(true);
            $table->unsignedInteger('max_workers')->default(3);
            $table->unsignedInteger('request_delay_min')->default(800);
            $table->unsignedInteger('request_delay_max')->default(2000);
            $table->unsignedInteger('timeout_seconds')->default(60);
            $table->timestamps();
        });

        DB::table('parser_settings')->insert([
            'download_photos' => true,
            'store_photo_links' => true,
            'max_workers' => 3,
            'request_delay_min' => 800,
            'request_delay_max' => 2000,
            'timeout_seconds' => 60,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('parser_settings');
    }
};
