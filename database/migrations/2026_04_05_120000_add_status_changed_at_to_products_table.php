<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->timestamp('status_changed_at')->nullable()->after('status');
        });

        // One-time: align with last row touch so existing rows are not counted as "new" errors by mistake
        DB::statement('UPDATE products SET status_changed_at = updated_at WHERE status_changed_at IS NULL');
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('status_changed_at');
        });
    }
};
