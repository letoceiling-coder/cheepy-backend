<?php

namespace Database\Seeders;

use App\Models\CmsPage;
use App\Models\CmsPageBlock;
use App\Models\CmsPageVersion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Макет главной для CRM (database/data/homepage_layout_spec.json).
 * Первый запуск создаёт system:homepage. Повторный — пропуск, если не задано CMS_SEED_OVERWRITE_HOMEPAGE=true.
 */
class CmsBuiltinPagesSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('data/homepage_layout_spec.json');
        if (! File::exists($path)) {
            $this->command->warn('CmsBuiltinPagesSeeder: нет файла '.$path);

            return;
        }

        $rows = json_decode(File::get($path), true);
        if (! is_array($rows) || $rows === []) {
            $this->command->warn('CmsBuiltinPagesSeeder: пустой или невалидный JSON');

            return;
        }

        $overwrite = filter_var((string) env('CMS_SEED_OVERWRITE_HOMEPAGE', ''), FILTER_VALIDATE_BOOL);
        $exists = CmsPage::query()->where('page_key', 'system:homepage')->exists();

        if ($exists && ! $overwrite) {
            $this->command->info('CmsBuiltinPagesSeeder: пропуск — system:homepage уже есть. Обновить блоки: CMS_SEED_OVERWRITE_HOMEPAGE=true php artisan db:seed --class=CmsBuiltinPagesSeeder --force');

            return;
        }

        DB::transaction(function () use ($rows) {
            $page = CmsPage::query()->firstOrCreate(
                ['page_key' => 'system:homepage'],
                [
                    'page_type' => 'system',
                    'path_prefix' => 'p',
                    'slug' => 'homepage',
                    'title' => 'Главная страница (макет)',
                    'is_active' => true,
                    'status' => CmsPage::STATUS_DRAFT,
                    'published_version_id' => null,
                ]
            );

            $version = $page->versions()->orderByDesc('version_number')->first();
            if (! $version) {
                $version = $page->versions()->create([
                    'version_number' => 1,
                    'status' => 'draft',
                ]);
            }

            CmsPageBlock::query()->where('cms_page_version_id', $version->id)->delete();

            foreach ($rows as $i => $row) {
                if (! is_array($row) || empty($row['type'])) {
                    continue;
                }
                $type = $row['type'];
                $settings = isset($row['settings']) && is_array($row['settings']) ? $row['settings'] : [];

                CmsPageBlock::create([
                    'cms_page_version_id' => $version->id,
                    'block_type' => $type,
                    'sort_order' => $i * 10,
                    'settings' => $settings,
                    'client_key' => sprintf('hp-%03d-%s', $i, $type),
                    'is_visible' => true,
                ]);
            }

            $page->update([
                'status' => CmsPage::STATUS_PUBLISHED,
                'published_version_id' => $version->id,
            ]);
            $version->update(['status' => 'published']);
        });

        $this->command->info('CmsBuiltinPagesSeeder: system:homepage, '.count($rows).' блоков, опубликовано');
    }
}
