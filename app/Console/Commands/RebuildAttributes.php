<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\AttributeExtractionService;
use Illuminate\Console\Command;

class RebuildAttributes extends Command
{
    protected $signature = 'attributes:rebuild
                            {--chunk=200   : Products processed per DB chunk}
                            {--product=    : Rebuild for a single product_id only}
                            {--category=   : Rebuild only for products in this category_id}
                            {--dry-run     : Extract without saving to DB}';

    protected $description = 'Rebuild product_attributes for all (or filtered) products using attribute_rules table';

    public function handle(AttributeExtractionService $service): int
    {
        $chunkSize = (int) ($this->option('chunk') ?: 200);

        // ── single product mode ───────────────────────────────────────
        if ($id = $this->option('product')) {
            $product = Product::find((int) $id);
            if (!$product) {
                $this->error("Product #{$id} not found.");
                return 1;
            }

            if ($this->option('dry-run')) {
                $text  = $product->title . "\n" . $product->description;
                $attrs = $service->extractFromText($text);
            } else {
                $attrs = $service->extractAndSave($product);
            }

            $this->info("Saved " . count($attrs) . " attributes for product #{$id}.");
            foreach ($attrs as $a) {
                $this->line(sprintf(
                    "  [%s] %-30s: %-30s  conf=%.2f",
                    $a['attribute_key'],
                    $a['attr_name'],
                    $a['attr_value'],
                    $a['confidence'] ?? 1.0
                ));
            }
            return 0;
        }

        // ── bulk mode ─────────────────────────────────────────────────
        $categoryId = $this->option('category') ? (int) $this->option('category') : null;
        $dryRun     = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN — no data will be saved.');
        }
        if ($categoryId) {
            $this->info("Filtering to category_id = {$categoryId}");
        }

        $total = Product::when($categoryId, fn($q) => $q->where('category_id', $categoryId))->count();
        $bar   = $this->output->createProgressBar($total);
        $bar->start();

        $processed = 0;
        $saved     = 0;

        if ($dryRun) {
            Product::select(['id', 'title', 'description', 'category_id'])
                ->when($categoryId, fn($q) => $q->where('category_id', $categoryId))
                ->orderBy('id')
                ->chunk($chunkSize, function ($products) use (&$processed, &$saved, $service, $bar) {
                    foreach ($products as $product) {
                        $text  = $product->title . "\n" . $product->description;
                        $attrs = $service->extractFromText($text);
                        $processed++;
                        $saved += count($attrs);
                        $bar->advance();
                    }
                });
        } else {
            $result = $service->rebuildAll(
                progress: function () use ($bar) { $bar->advance(); },
                categoryId: $categoryId,
                chunkSize: $chunkSize
            );
            $processed = $result['processed'];
            $saved     = $result['saved'];
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Done. Processed: {$processed} products, saved: {$saved} attributes.");
        $this->line("  Avg per product: " . ($processed > 0 ? round($saved / $processed, 1) : 0));

        return 0;
    }
}
