<?php

namespace App\Console\Commands;

use App\Services\Catalog\CatalogSyncService;
use Illuminate\Console\Command;

class CatalogSyncCommand extends Command
{
    protected $signature = 'catalog:sync
                            {--dry-run : Show counts without modifying the database}';

    protected $description = 'Sync categories table into catalog_categories (slug-based, tree-safe, never deletes)';

    public function handle(CatalogSyncService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info('Catalog Sync Started' . ($dryRun ? ' (dry run)' : ''));

        $result = $service->sync($dryRun);

        $this->line('Total categories processed: ' . $result['total']);
        $this->line('Created: ' . $result['created']);
        $this->line('Updated: ' . $result['updated']);
        $this->line('Skipped: ' . $result['skipped']);

        if ($dryRun) {
            $this->comment('Dry run — no changes were made.');
        }

        return 0;
    }
}
