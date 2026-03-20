<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('provider', 50)->default('stripe')->after('amount');
            $table->string('provider_id', 255)->nullable()->after('status');
            $table->index(['provider', 'provider_id']);
        });

        if (Schema::hasColumn('payments', 'stripe_id')) {
            DB::table('payments')
                ->whereNull('provider_id')
                ->whereNotNull('stripe_id')
                ->update(['provider_id' => DB::raw('stripe_id')]);
        }

        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'stripe_id')) {
                $table->dropUnique(['stripe_id']);
                $table->dropColumn('stripe_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('stripe_id', 255)->nullable()->unique()->after('status');
        });

        DB::table('payments')
            ->whereNull('stripe_id')
            ->where('provider', 'stripe')
            ->whereNotNull('provider_id')
            ->update(['stripe_id' => DB::raw('provider_id')]);

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['provider', 'provider_id']);
            $table->dropColumn(['provider', 'provider_id']);
        });
    }
};
