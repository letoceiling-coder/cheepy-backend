<?php

namespace App\Services\Parser;

use App\Models\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Полная очистка донорского слоя парсера: products и все зависящие строки.
 * CRM-карточки (system_products) не удаляются; из product_sources убираются только связи с донором.
 */
class DonorProductsWipeService
{
    /**
     * @param  bool  $truncateParserLogsAndJobs  очистить parser_logs и parser_jobs
     */
    public function wipe(bool $truncateParserLogsAndJobs = true): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            if ($truncateParserLogsAndJobs && Schema::hasTable('parser_logs')) {
                DB::table('parser_logs')->truncate();
                $this->resetAutoIncrement('parser_logs');
            }
            if ($truncateParserLogsAndJobs && Schema::hasTable('parser_jobs')) {
                DB::table('parser_jobs')->truncate();
                $this->resetAutoIncrement('parser_jobs');
            }
            if (Schema::hasTable('product_similar')) {
                DB::table('product_similar')->truncate();
                $this->resetAutoIncrement('product_similar');
            }
            if (Schema::hasTable('product_sources')) {
                DB::table('product_sources')->truncate();
                $this->resetAutoIncrement('product_sources');
            }
            DB::table('product_attributes')->truncate();
            DB::table('product_photos')->truncate();
            DB::table('products')->truncate();

            $this->resetAutoIncrement('products');
            $this->resetAutoIncrement('product_photos');
            $this->resetAutoIncrement('product_attributes');
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        DB::table('sellers')->update(['products_count' => 0]);
        Category::query()->update(['products_count' => 0]);
    }

    private function resetAutoIncrement(string $table): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE `'.$table.'` AUTO_INCREMENT = 1');
        }
    }
}
