<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('constructor_layout_templates', function (Blueprint $table) {
            $table->string('template_type', 24)->default('content')->after('description');
            $table->string('page_scope', 24)->default('page')->after('template_type');
            $table->string('page_key', 128)->nullable()->after('page_scope');
            $table->boolean('is_editable')->default(true)->after('is_system');
            $table->boolean('is_active')->default(true)->after('is_editable');
            $table->index(['template_type', 'page_scope'], 'idx_cl_templates_type_scope');
        });

        Schema::table('constructor_layout_template_blocks', function (Blueprint $table) {
            $table->boolean('is_enabled')->default(true)->after('client_key');
            $table->boolean('is_required')->default(false)->after('is_enabled');
            $table->boolean('is_locked')->default(false)->after('is_required');
            $table->string('slot_key', 80)->nullable()->after('is_locked');
        });

        DB::table('constructor_layout_templates')
            ->where('is_system', true)
            ->update(['template_type' => 'system']);

        if (Schema::hasColumn('constructor_layout_template_blocks', 'is_visible')) {
            DB::statement('UPDATE constructor_layout_template_blocks SET is_enabled = COALESCE(is_visible, 1)');
        }
    }

    public function down(): void
    {
        Schema::table('constructor_layout_template_blocks', function (Blueprint $table) {
            $table->dropColumn(['slot_key', 'is_locked', 'is_required', 'is_enabled']);
        });

        Schema::table('constructor_layout_templates', function (Blueprint $table) {
            $table->dropIndex('idx_cl_templates_type_scope');
            $table->dropColumn(['is_active', 'is_editable', 'page_key', 'page_scope', 'template_type']);
        });
    }
};
