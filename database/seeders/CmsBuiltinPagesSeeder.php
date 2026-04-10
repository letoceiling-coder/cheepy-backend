<?php

namespace Database\Seeders;

use App\Models\CmsPage;
use App\Models\CmsPageBlock;
use App\Models\CmsPageVersion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Макет главной для CRM: блоки и порядок как на витрине (database/data/homepage_layout_spec.json).
 * Идемпотентно обновляет блоки последней версии страницы system:homepage.
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

        if (CmsPage::query()->where('page_key', 'system:homepage')->exists()) {
            $this->command->info('CmsBuiltinPagesSeeder: пропуск — страница system:homepage уже есть (удалите её в БД, чтобы залить макет снова)');

            return;
        }

        DB::transaction(function () use ($rows) {
            $page = CmsPage::query()->create([
                'page_key' => 'system:homepage',
                'page_type' => 'system',
                'path_prefix' => 'p',
                'slug' => 'homepage',
                'title' => 'Главная страница (макет)',
                'is_active' => true,
                'status' => CmsPage::STATUS_DRAFT,
                'published_version_id' => null,
            ]);

            $version = $page->versions()->create([
                'version_number' => 1,
                'status' => 'draft',
            ]);

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
