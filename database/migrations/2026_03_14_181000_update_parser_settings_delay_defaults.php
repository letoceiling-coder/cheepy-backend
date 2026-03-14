<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('parser_settings')
            ->where('request_delay_min', '<', 1500)
            ->update(['request_delay_min' => 1500, 'updated_at' => now()]);

        DB::table('parser_settings')
            ->where('request_delay_max', '<', 3000)
            ->update(['request_delay_max' => 3000, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('parser_settings')
            ->where('request_delay_min', '>', 800)
            ->update(['request_delay_min' => 800, 'updated_at' => now()]);

        DB::table('parser_settings')
            ->where('request_delay_max', '>', 2000)
            ->update(['request_delay_max' => 2000, 'updated_at' => now()]);
    }
};
