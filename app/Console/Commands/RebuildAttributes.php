<?php

namespace App\Console\Commands;

use App\Services\AttributeExtractionService;
use Illuminate\Console\Command;

class RebuildAttributes extends Command
{
    protected $signature   = 'attributes:rebuild
                              {--chunk=200 : Products processed per DB chunk}
                              {--product= : Rebuild for a single product_id only}';
    protected $description = 'Rebuild product_attributes for all products using attribute_rules table';

    public function handle(AttributeExtractionService $service): int
    {
        if ($id = $this->option('product')) {
            $product = \App\Models\Product::find((int) $id);
            if (!$product) {
                $this->error("Product #{$id} not found.");
                return 1;
            }
            $attrs = $service->extractAndSave($product);
            $this->info("Saved " . count($attrs) . " attributes for product #{$id}.");
            foreach ($attrs as $a) {
                $this->line("  [{$a['attribute_key']}] {$a['attr_name']}: {$a['attr_value']}");
            }
            return 0;
        }

        $total = \App\Models\Product::count();
        $bar   = $this->output->createProgressBar($total);
        $bar->start();

        $result = $service->rebuildAll(function () use ($bar) {
            $bar->advance();
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Done. Processed: {$result['processed']} products, saved: {$result['saved']} attributes.");
        return 0;
    }
}
