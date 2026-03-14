<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('parser_settings') || !Schema::hasColumn('parser_settings', 'queue_threshold')) {
            return;
        }

        DB::table('parser_settings')
            ->where('queue_threshold', '>', 500)
            ->update([
                'queue_threshold' => 500,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // no-op
    }
};
