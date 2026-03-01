<?php

namespace App\Console\Commands;

use App\Services\SadovodParser\SadovodParserService;
use Illuminate\Console\Command;

class ParseSadovodCommand extends Command
{
    protected $signature = 'sadovod:parse
                            {--menu-only : Only parse menu and categories}
                            {--categories= : Limit number of categories to parse (default: all)}
                            {--products-per-cat= : Limit products per category (default: all)}
                            {--no-details : Skip product and seller detail parsing}
                            {--output= : Output JSON file path (default: storage/app/sadovod-result.json)}';

    protected $description = 'Parse sadovodbaza.ru: categories, products, sellers. Output JSON.';

    public function handle(): int
    {
        $config = config('sadovod', []);
        $service = new SadovodParserService($config);

        $menuOnly = $this->option('menu-only');
        $categoriesLimit = (int) $this->option('categories');
        $productsPerCat = (int) $this->option('products-per-cat');
        $noDetails = $this->option('no-details');
        $outputPath = $this->option('output') ?: storage_path('app/sadovod-result.json');

        if ($menuOnly) {
            $this->info('Parsing menu and categories only...');
            $result = $service->parseMenu();
        } else {
            $this->info('Running full parser...');
            $result = $service->run([
                'categories_limit' => $categoriesLimit ?: 0,
                'products_per_category_limit' => $productsPerCat ?: 0,
                'parse_product_details' => !$noDetails,
                'parse_sellers' => !$noDetails,
            ]);
        }

        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            $this->error('JSON encode failed.');
            return self::FAILURE;
        }

        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($outputPath, $json);
        $this->info('Saved to ' . $outputPath);

        return self::SUCCESS;
    }
}
