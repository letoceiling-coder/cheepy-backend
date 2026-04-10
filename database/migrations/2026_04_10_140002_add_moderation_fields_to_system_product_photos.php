<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_product_photos', function (Blueprint $table) {
            $table->boolean('is_enabled')->default(true)->after('sort_order');
            $table->foreignId('media_file_id')->nullable()->after('is_enabled')->constrained('crm_media_files')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('system_product_photos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('media_file_id');
            $table->dropColumn('is_enabled');
        });
    }
};
