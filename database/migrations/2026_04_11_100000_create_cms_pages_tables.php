<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CMS: страницы конструктора, версии, блоки с расширяемым JSON settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_pages', function (Blueprint $table) {
            $table->id();
            $table->string('page_key')->unique();
            $table->string('page_type', 32)->default('custom')->index();
            $table->string('path_prefix', 32)->default('p');
            $table->string('slug');
            $table->string('title');
            $table->boolean('is_active')->default(true)->index();
            $table->string('status', 20)->default('draft')->index();
            $table->string('seo_title')->nullable();
            $table->string('seo_description', 512)->nullable();
            $table->string('og_title')->nullable();
            $table->string('og_description', 512)->nullable();
            $table->string('og_image_url', 1024)->nullable();
            $table->string('canonical_url', 1024)->nullable();
            $table->string('robots', 64)->nullable();
            $table->json('seo_extra')->nullable();
            $table->timestamps();

            $table->unique(['path_prefix', 'slug']);
        });

        Schema::create('cms_page_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cms_page_id')->constrained('cms_pages')->cascadeOnDelete();
            $table->unsignedInteger('version_number')->default(1);
            $table->string('status', 20)->default('draft')->index();
            $table->timestamps();

            $table->unique(['cms_page_id', 'version_number']);
        });

        Schema::create('cms_page_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cms_page_version_id')->constrained('cms_page_versions')->cascadeOnDelete();
            $table->string('block_type', 120)->index();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->json('settings')->nullable()->comment('Extensible per block_type; same block, different data per page');
            $table->string('client_key', 64)->nullable()->comment('Stable id from constructor BlockConfig.id');
            $table->boolean('is_visible')->default(true);
            $table->timestamps();

            $table->index(['cms_page_version_id', 'sort_order']);
        });

        Schema::table('cms_pages', function (Blueprint $table) {
            $table->foreignId('published_version_id')->nullable()->after('status')->constrained('cms_page_versions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cms_pages', function (Blueprint $table) {
            $table->dropForeign(['published_version_id']);
            $table->dropColumn('published_version_id');
        });
        Schema::dropIfExists('cms_page_blocks');
        Schema::dropIfExists('cms_page_versions');
        Schema::dropIfExists('cms_pages');
    }
};
