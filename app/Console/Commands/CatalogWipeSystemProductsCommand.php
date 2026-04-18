<?php

namespace App\Console\Commands;

use App\Services\Catalog\CrmSystemProductsWipeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CatalogWipeSystemProductsCommand extends Command
{
    protected $signature = 'catalog:wipe-system-products
                            {--force : Выполнить без подтверждения}';

    protected $description = 'Удалить все CRM-товары (system_products, фото, атрибуты, product_sources). Донор products не меняется.';

    public function handle(CrmSystemProductsWipeService $wipe): int
    {
        if (! $this->option('force') && ! $this->confirm(
            'Удалить ВСЕ записи в system_products и связанных таблицах CRM?',
            false
        )) {
            $this->warn('Отменено.');

            return self::SUCCESS;
        }

        $this->info('Очистка CRM-каталога (system_products)…');
        $wipe->wipe();

        $n = Schema::hasTable('system_products') ? DB::table('system_products')->count() : 0;
        $this->info("Готово. system_products={$n}");

        return self::SUCCESS;
    }
}
