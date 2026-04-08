<?php

namespace App\Console\Commands;

use App\Support\Testing\SafeApiTestingGuards;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TestingSafeApiCleanupCommand extends Command
{
    protected $signature = 'testing:safe-api-cleanup';

    protected $description = 'Remove isolated API test rows (donor_category_id >= 1000000) from test DB only';

    public function handle(): int
    {
        SafeApiTestingGuards::assertTestingDatabase();

        $deletedMaps = DB::delete('DELETE FROM category_mapping WHERE donor_category_id >= ?', [1_000_000]);
        $this->info("Deleted category_mapping rows (donor_category_id >= 1000000): {$deletedMaps}");

        $deletedDonors = DB::delete('DELETE FROM donor_categories WHERE id >= ?', [1_000_000]);
        $this->info("Deleted donor_categories rows (id >= 1000000): {$deletedDonors}");

        return self::SUCCESS;
    }
}
