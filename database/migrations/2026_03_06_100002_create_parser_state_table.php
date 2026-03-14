<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parser_state', function (Blueprint $table) {
            $table->id();
            $table->string('status', 32)->default('stopped'); // running, stopped, paused
            $table->boolean('locked')->default(false);
            $table->timestamp('last_start')->nullable();
            $table->timestamp('last_stop')->nullable();
            $table->timestamps();
        });

        DB::table('parser_state')->insert([
            'status' => 'stopped',
            'locked' => false,
            'updated_at' => now(),
            'created_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('parser_state');
    }
};
