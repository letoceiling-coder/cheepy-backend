<?php

namespace App\Services\Catalog;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Полная очистка витринного слоя CRM: system_products и связи (фото, атрибуты, product_sources).
 * Донорский слой `products` не трогается.
 */
class CrmSystemProductsWipeService
{
    public function wipe(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            if (Schema::hasTable('product_sources')) {
                DB::table('product_sources')->truncate();
                $this->resetAutoIncrement('product_sources');
            }
            if (Schema::hasTable('system_product_attributes')) {
                DB::table('system_product_attributes')->truncate();
                $this->resetAutoIncrement('system_product_attributes');
            }
            if (Schema::hasTable('system_product_photos')) {
                DB::table('system_product_photos')->truncate();
                $this->resetAutoIncrement('system_product_photos');
            }
            if (Schema::hasTable('system_products')) {
                DB::table('system_products')->truncate();
                $this->resetAutoIncrement('system_products');
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    private function resetAutoIncrement(string $table): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `'.$table.'` AUTO_INCREMENT = 1');
        }
    }
}
