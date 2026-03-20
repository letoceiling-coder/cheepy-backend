<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('provider_event_id', 255)->nullable()->after('provider_id');
            $table->unique(['provider', 'provider_id']);
            $table->unique(['provider', 'provider_event_id']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['provider', 'provider_id']);
            $table->dropUnique(['provider', 'provider_event_id']);
            $table->dropColumn('provider_event_id');
        });
    }
};
