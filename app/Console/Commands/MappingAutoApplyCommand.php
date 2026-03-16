<?php

namespace App\Console\Commands;

use App\Services\Catalog\MappingApplyService;
use Illuminate\Console\Command;

class MappingAutoApplyCommand extends Command
{
    protected $signature = 'mapping:auto-apply
                            {--limit=500 : Max suggestions to consider (score >= 95 only)}';

    protected $description = 'Apply high-confidence mapping suggestions to category_mapping (score >= 95, no overwrite, no delete)';

    public function handle(MappingApplyService $service): int
    {
        $limit = (int) $this->option('limit');
        $limit = $limit > 0 ? min($limit, 5000) : 500;

        $this->info('Mapping Auto-Apply Started');

        $result = $service->applyAuto($limit);

        $this->line('Total suggestions: ' . $result['total']);
        $this->line('Created mappings: ' . $result['created']);
        $this->line('Skipped mappings: ' . $result['skipped']);

        return 0;
    }
}
