<?php

namespace App\Console\Commands;

use App\Services\Catalog\DonorSyncService;
use Illuminate\Console\Command;

class DonorSyncCommand extends Command
{
    protected $signature = 'donor:sync';

    protected $description = 'Sync categories table into donor_categories (external_id-based, tree-safe, never deletes)';

    public function handle(DonorSyncService $service): int
    {
        $this->info('Donor Sync Started');

        $result = $service->sync();

        $this->line('Total processed: ' . $result['total']);
        $this->line('Created: ' . $result['created']);
        $this->line('Updated: ' . $result['updated']);

        return 0;
    }
}
