<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_mapping_logs', function (Blueprint $table): void {
            $table->float('ai_score')->nullable()->after('confidence');
            $table->float('legacy_score')->nullable()->after('ai_score');
            $table->float('final_score')->nullable()->after('legacy_score');
            $table->float('boost_applied')->nullable()->after('final_score');
            $table->string('decision_reason', 512)->nullable()->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('auto_mapping_logs', function (Blueprint $table): void {
            $table->dropColumn([
                'ai_score',
                'legacy_score',
                'final_score',
                'boost_applied',
                'decision_reason',
            ]);
        });
    }
};

