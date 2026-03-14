<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parser_logs', function (Blueprint $table): void {
            if (!Schema::hasColumn('parser_logs', 'type')) {
                $table->string('type', 20)->nullable()->after('level');
                $table->index('type');
            }
            if (!Schema::hasColumn('parser_logs', 'url')) {
                $table->string('url', 1000)->nullable()->after('message');
            }
            if (!Schema::hasColumn('parser_logs', 'product_id')) {
                $table->unsignedBigInteger('product_id')->nullable()->after('url');
                $table->index('product_id');
            }
            if (!Schema::hasColumn('parser_logs', 'attempt')) {
                $table->unsignedInteger('attempt')->nullable()->after('product_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('parser_logs', function (Blueprint $table): void {
            foreach (['type', 'url', 'product_id', 'attempt'] as $column) {
                if (Schema::hasColumn('parser_logs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
