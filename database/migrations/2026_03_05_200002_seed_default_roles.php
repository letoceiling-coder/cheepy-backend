<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $roles = [
            ['name' => 'Administrator', 'slug' => 'admin', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Editor', 'slug' => 'editor', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Viewer', 'slug' => 'viewer', 'created_at' => now(), 'updated_at' => now()],
        ];
        foreach ($roles as $r) {
            DB::table('roles')->insertOrIgnore($r);
        }
    }

    public function down(): void
    {
        DB::table('roles')->whereIn('slug', ['admin', 'editor', 'viewer'])->delete();
    }
};
