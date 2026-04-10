<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductSource;
use App\Models\SystemProduct;
use App\Services\Catalog\SystemProductFromDonorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Разовая (или повторная) догрузка: все донорные товары без связи product_sources → system_products.
 * Новые прогоны парсера можно подключать отдельно (job); эта команда закрывает уже существующую базу.
 */
class BackfillSystemProductsFromDonorCommand extends Command
{
    protected $signature = 'catalog:backfill-system-products
                            {--chunk=500 : Размер чанка}
                            {--status=pending : Статус создаваемых system_products (draft|pending|approved|published)}
                            {--dry-run : Только посчитать, не создавать}
                            {--skip-errors : Продолжать при ошибке по одному товару}';

    protected $description = 'Создать system_products для уже спарсенных products, у которых ещё нет связи product_sources (parser)';

    public function handle(SystemProductFromDonorService $fromDonorService): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $status = (string) $this->option('status');
        $allowed = [
            SystemProduct::STATUS_DRAFT,
            SystemProduct::STATUS_PENDING,
            SystemProduct::STATUS_APPROVED,
            SystemProduct::STATUS_PUBLISHED,
        ];
        if (! in_array($status, $allowed, true)) {
            $this->error('Недопустимый --status. Допустимо: '.implode(', ', $allowed));

            return 1;
        }

        $dryRun = (bool) $this->option('dry-run');
        $skipErrors = (bool) $this->option('skip-errors');

        $this->info('Перед массовым импортом категорий рекомендуется: php artisan donor:sync');
        $this->newLine();

        $baseQuery = Product::query()
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('product_sources')
                    ->whereColumn('product_sources.donor_product_id', 'products.id')
                    ->where('product_sources.source', ProductSource::SOURCE_PARSER);
            });

        $total = (clone $baseQuery)->count();
        $this->line("Товаров без CRM-связи (parser): {$total}");

        if ($dryRun) {
            $this->warn('Dry-run: записи не создаются.');

            return 0;
        }

        if ($total === 0) {
            return 0;
        }

        $created = 0;
        $failed = 0;

        $baseQuery->orderBy('products.id')
            ->chunkById($chunk, function ($products) use ($fromDonorService, $status, $skipErrors, &$created, &$failed) {
                foreach ($products as $product) {
                    try {
                        $fromDonorService->createFromDonor($product, $status);
                        $created++;
                    } catch (\Throwable $e) {
                        $failed++;
                        $this->error("product_id={$product->id}: ".$e->getMessage());
                        if (! $skipErrors) {
                            return false;
                        }
                    }
                }

                return true;
            }, 'products.id', 'id');

        $this->newLine();
        $this->info("Создано system_products: {$created}");
        if ($failed > 0) {
            $this->warn("Ошибок: {$failed}");
        }

        return $failed > 0 && ! $skipErrors ? 1 : 0;
    }
}
