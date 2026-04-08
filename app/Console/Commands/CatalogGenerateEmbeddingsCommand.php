<?php

namespace App\Console\Commands;

use App\Models\CatalogCategory;
use App\Services\AI\EmbeddingService;
use Illuminate\Console\Command;

class CatalogGenerateEmbeddingsCommand extends Command
{
    protected $signature = 'catalog:generate-embeddings
                            {--only-missing : Skip categories that already have embedding}
                            {--limit=0 : Process only first N categories}';

    protected $description = 'Generate OpenAI embeddings for catalog category names';

    public function handle(EmbeddingService $embeddingService): int
    {
        $onlyMissing = (bool) $this->option('only-missing');
        $limit = max(0, (int) $this->option('limit'));

        $query = CatalogCategory::query()->orderBy('id');
        if ($onlyMissing) {
            $query->whereNull('embedding');
        }
        if ($limit > 0) {
            $query->limit($limit);
        }

        $rows = $query->get(['id', 'name', 'embedding']);
        $total = $rows->count();
        $done = 0;

        $this->info("Generating embeddings for {$total} catalog categories...");

        foreach ($rows as $row) {
            $vector = $embeddingService->embed('category: '.(string) $row->name);
            $row->embedding = $vector;
            $row->save();
            $done++;
        }

        $this->info("Done. Updated categories: {$done}");

        return self::SUCCESS;
    }
}

