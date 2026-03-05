<?php

namespace App\Services;

use App\Events\ParserError;
use App\Events\ParserFinished;
use App\Events\ParserProgressUpdated;
use App\Events\ParserStarted;
use App\Events\ProductParsed;
use App\Models\Category;
use App\Models\ParserJob;
use App\Models\ParserLog;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductPhoto;
use App\Models\Seller;
use App\Services\SadovodParser\HttpClient;
use App\Services\SadovodParser\Parsers\CatalogParser;
use App\Services\SadovodParser\Parsers\MenuParser;
use App\Services\SadovodParser\Parsers\ProductParser;
use App\Services\SadovodParser\Parsers\SellerParser;
use Illuminate\Support\Str;

class DatabaseParserService
{
    private HttpClient $http;
    private CatalogParser $catalogParser;
    private ProductParser $productParser;
    private SellerParser $sellerParser;
    private MenuParser $menuParser;
    private PhotoDownloadService $photoService;
    private ParserJob $job;

    private array $options;

    public function __construct(ParserJob $job)
    {
        $this->job = $job;
        $this->options = $job->options ?? [];

        $config = config('sadovod');
        $this->http = new HttpClient($config);
        $this->catalogParser = new CatalogParser($this->http);
        $this->productParser = new ProductParser($this->http);
        $this->sellerParser = new SellerParser($this->http);
        $this->menuParser = new MenuParser($this->http);
        $this->photoService = new PhotoDownloadService();
    }

    /**
     * Запустить парсинг в соответствии с job->type
     */
    public function run(): void
    {
        $this->updateJob(['status' => 'running', 'started_at' => now()]);
        $this->job->refresh();
        event(new ParserStarted($this->job));

        try {
            match ($this->job->type) {
                'menu_only' => $this->runMenuOnly(),
                'category'  => $this->runSingleCategory($this->options['category_slug'] ?? ''),
                'seller'    => $this->runSingleSeller($this->options['seller_slug'] ?? ''),
                default     => $this->runFull(),
            };

            $this->updateJob(['status' => 'completed', 'finished_at' => now()]);
            $this->job->refresh();
            event(new ParserFinished($this->job));
            $this->log('info', 'Парсинг завершён успешно', [
                'products' => $this->job->saved_products,
                'errors' => $this->job->errors_count,
            ]);
        } catch (\Throwable $e) {
            $this->updateJob([
                'status' => 'failed',
                'finished_at' => now(),
                'error_message' => $e->getMessage(),
            ]);
            $this->job->refresh();
            event(new ParserError($this->job, $e->getMessage(), ['trace' => $e->getTraceAsString()]));
            event(new ParserFinished($this->job));
            $this->log('error', 'Парсинг завершился ошибкой: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // MENU
    // -------------------------------------------------------------------------

    private function runMenuOnly(): void
    {
        $this->updateAction('Загрузка меню категорий...');
        // parse() без аргументов — сам делает GET /
        $result = $this->menuParser->parse(null);
        $categories = $result['categories'] ?? [];
        $this->saveCategories($categories);
        $this->log('info', 'Меню загружено', ['count' => count($categories)]);
    }

    private function saveCategories(array $categories, ?int $parentId = null, int $depth = 0): void
    {
        foreach ($categories as $index => $cat) {
            $slug = $this->extractSlug($cat['url'] ?? '');
            if (!$slug) continue;

            $category = Category::updateOrCreate(
                ['external_slug' => $slug],
                [
                    'name' => $cat['title'] ?? $slug,
                    'slug' => $slug,
                    'url' => $cat['url'] ?? null,
                    'parent_id' => $parentId,
                    'sort_order' => $depth * 100 + $index,
                    'enabled' => true,
                ]
            );

            // Рекурсивно сохранить дочерние
            if (!empty($cat['children'])) {
                $this->saveCategories($cat['children'], $category->id, $depth + 1);
            }
        }
    }

    // -------------------------------------------------------------------------
    // FULL PARSE
    // -------------------------------------------------------------------------

    private function runFull(): void
    {
        // 1. Загружаем/обновляем категории
        $this->runMenuOnly();

        // 2. Определяем категории для парсинга
        $categoryFilter = $this->options['categories'] ?? [];
        $query = Category::where('enabled', true);

        if (!empty($categoryFilter)) {
            $query->whereIn('external_slug', $categoryFilter);
        } elseif (!empty($this->options['linked_only'])) {
            $query->where('linked_to_parser', true);
        }

        $categories = $query->orderBy('sort_order')->get();
        $this->updateJob(['total_categories' => $categories->count()]);

        // 3. Парсим каждую категорию с пагинацией
        foreach ($categories as $category) {
            if ($this->isCancelled()) break;
            $this->runSingleCategory($category->external_slug, $category);
        }
    }

    // -------------------------------------------------------------------------
    // SINGLE CATEGORY
    // -------------------------------------------------------------------------

    private function runSingleCategory(string $slug, ?Category $category = null): void
    {
        if (!$category) {
            $category = Category::where('external_slug', $slug)->first();
        }

        $this->updateAction("Категория: {$slug}");
        $this->updateJob(['current_category_slug' => $slug]);

        $productsPerPage = 24; // по умолчанию на странице донора
        $maxPages = $this->options['max_pages'] ?? ($category?->parser_max_pages ?? 0);
        $productLimit = $this->options['products_per_category'] ?? ($category?->parser_products_limit ?? 0);
        $savePhotos = $this->options['save_photos'] ?? false;
        $saveDetails = !($this->options['no_details'] ?? false);

        $page = 1;
        $savedCount = 0;

        while (true) {
            if ($this->isCancelled()) break;

            $this->updateAction("Категория: {$slug} | Страница {$page}" . ($maxPages ? "/{$maxPages}" : ''));
            $this->updateJob(['current_page' => $page]);

            try {
                $result = $this->catalogParser->parseCategory('/catalog/' . $slug, $page - 1, $productsPerPage);
                $products = $result['products'] ?? [];
                $hasMore = $result['has_more'] ?? false;

                if (empty($products)) break;

                // Первая страница — определяем totalPages
                if ($page === 1) {
                    $totalPages = $result['total_pages'] ?? 1;
                    if ($maxPages > 0) {
                        $totalPages = min($totalPages, $maxPages);
                    }
                    $this->updateJob(['total_pages' => $totalPages]);
                }

                foreach ($products as $pData) {
                    if ($this->isCancelled()) break 2;
                    if ($productLimit > 0 && $savedCount >= $productLimit) break 2;

                    $saved = $this->saveProductFromListing($pData, $category, $saveDetails, $savePhotos);
                    if ($saved) {
                        $savedCount++;
                        $this->job->refresh();
                        if ($savedCount % 10 === 0) {
                            event(new ParserProgressUpdated($this->job));
                        }
                    }
                }

                $this->job->increment('parsed_categories');
                $this->job->refresh();

                if (!$hasMore || ($maxPages > 0 && $page >= $maxPages)) break;
                if ($productLimit > 0 && $savedCount >= $productLimit) break;

                $page++;
                usleep((int) (config('sadovod.request_delay_ms', 500) * 1000));
            } catch (\Throwable $e) {
                $this->log('error', "Ошибка парсинга страницы {$page} категории {$slug}: " . $e->getMessage());
                $this->job->increment('errors_count');
                $this->job->refresh();
                event(new ParserError($this->job, "Ошибка парсинга страницы {$page} категории {$slug}: " . $e->getMessage()));
                break;
            }
        }

        $category?->update([
            'products_count' => $savedCount,
            'last_parsed_at' => now(),
        ]);

        $this->log('info', "Категория {$slug}: сохранено {$savedCount} товаров");
    }

    // -------------------------------------------------------------------------
    // PRODUCT
    // -------------------------------------------------------------------------

    private function saveProductFromListing(array $pData, ?Category $category, bool $saveDetails, bool $savePhotos): bool
    {
        try {
            $externalId = (string) ($pData['id'] ?? '');
            if (!$externalId) return false;

            // Уточнённые детали с отдельной страницы товара
            if ($saveDetails) {
                try {
                    $detailData = $this->productParser->parse('/odejda/' . $externalId);
                    $pData = array_merge($pData, $detailData);
                    usleep((int) (config('sadovod.request_delay_ms', 500) * 1000));
                } catch (\Throwable $e) {
                    $this->log('warn', "Не удалось получить детали товара {$externalId}: " . $e->getMessage());
                }
            }

            // Продавец
            $seller = $this->upsertSeller($pData['seller'] ?? []);

            // Сохраняем продукт
            $product = Product::upsertFromParser($pData, $category?->id, $seller?->id);

            // Нормализованные атрибуты
            $this->saveProductAttributes($product, $pData['characteristics'] ?? [], $category);

            // Фото
            if ($savePhotos && !empty($pData['photos'])) {
                $result = $this->photoService->downloadProductPhotos($product);
                $this->job->increment('photos_downloaded', $result['downloaded']);
                $this->job->increment('photos_failed', $result['failed']);
            } else {
                // Создать записи без скачивания
                $this->createPhotoRecordsOnly($product, $pData['photos'] ?? []);
            }

            $this->job->increment('saved_products');
            $this->job->increment('parsed_products');
            $this->job->refresh();
            event(new ProductParsed($this->job, [
                'id' => $product->id,
                'external_id' => $product->external_id,
                'title' => $product->title ?? $pData['title'] ?? '',
            ]));
            return true;
        } catch (\Throwable $e) {
            $this->log('error', "Ошибка сохранения товара: " . $e->getMessage(), ['data' => $pData['id'] ?? '']);
            $this->job->increment('errors_count');
            $this->job->refresh();
            event(new ParserError($this->job, "Ошибка сохранения товара: " . $e->getMessage(), ['product_id' => $pData['id'] ?? null]));
            return false;
        }
    }

    private function saveProductAttributes(Product $product, array $characteristics, ?Category $category): void
    {
        if (empty($characteristics)) return;

        // Удаляем старые атрибуты продукта
        ProductAttribute::where('product_id', $product->id)->delete();

        $typeMap = [
            'color' => 'color', 'Цвет' => 'color',
            'size' => 'size', 'Размер' => 'size', 'size_range' => 'size',
        ];

        foreach ($characteristics as $name => $value) {
            if (!is_string($name) || !is_string($value)) continue;
            // Пропускаем мусорные значения: длинные, содержащие UI-текст
            if (mb_strlen($value) > 200) continue;
            if (preg_match('/Добавить в корзину|Позвонить|Смотреть все|В корзину|Уточнить/ui', $value)) continue;
            if (mb_strlen($name) > 195) continue;

            ProductAttribute::create([
                'product_id' => $product->id,
                'category_id' => $category?->id,
                'attr_name' => $name,
                'attr_value' => $value,
                'attr_type' => $typeMap[$name] ?? 'text',
            ]);
        }
    }

    private function createPhotoRecordsOnly(Product $product, array $photos): void
    {
        if (empty($photos)) return;

        foreach ($photos as $index => $url) {
            $normalUrl = str_starts_with($url, 'http')
                ? $url
                : config('sadovod.base_url', 'https://sadovodbaza.ru') . '/' . ltrim($url, '/');

            ProductPhoto::firstOrCreate(
                ['product_id' => $product->id, 'original_url' => $normalUrl],
                [
                    'medium_url' => str_replace('_img_big.', '_img_medium.', $normalUrl),
                    'sort_order' => $index,
                    'is_primary' => $index === 0,
                    'download_status' => 'pending',
                ]
            );
        }
    }

    // -------------------------------------------------------------------------
    // SELLER
    // -------------------------------------------------------------------------

    private function runSingleSeller(string $slug): void
    {
        $this->updateAction("Продавец: {$slug}");
        try {
            $data = $this->sellerParser->parse('/s/' . $slug);
            $this->upsertSeller($data);
            $this->log('info', "Продавец {$slug} обновлён");
        } catch (\Throwable $e) {
            $this->log('error', "Ошибка парсинга продавца {$slug}: " . $e->getMessage());
            $this->job->increment('errors_count');
        }
    }

    private function upsertSeller(array $sellerData): ?Seller
    {
        if (empty($sellerData) || empty($sellerData['name'])) return null;

        $slug = $sellerData['slug'] ?? Str::slug($sellerData['name']);
        if (!$slug) return null;

        // Извлекаем павильон "13-53", "9-36", "9 линия 39" из pavilion строки
        $pavilion = $sellerData['pavilion'] ?? '';
        $pavilionLine = null;
        $pavilionNumber = null;
        if (preg_match('/(\d+)\s*линия\s+(\d+)/u', $pavilion, $m)) {
            $pavilionLine = $m[1];
            $pavilionNumber = $m[2];
        } elseif (preg_match('/(\d+)-(\d+)/', $pavilion, $m)) {
            $pavilionLine = $m[1];
            $pavilionNumber = $m[2];
        }

        // Извлечь WhatsApp номер из URL
        $whatsappUrl = $sellerData['contacts']['whatsapp'] ?? null;
        $whatsappNumber = null;
        if ($whatsappUrl && preg_match('/wa\.me\/(\d+)/', $whatsappUrl, $m)) {
            $whatsappNumber = '+' . $m[1];
        }

        // Извлечь shop ID
        $shopId = null;
        if ($whatsappUrl && preg_match('/utm_content=shop(\d+)/', $whatsappUrl, $m)) {
            $shopId = $m[1];
        }

        // Очистить pavilion от CSS/мусора (брать только первую строку до переноса или до CSS)
        $cleanPavilion = $pavilion;
        if (preg_match('/^([^\n\r\.{]+)/u', $pavilion, $pm)) {
            $cleanPavilion = trim($pm[1]);
        }
        $cleanPavilion = mb_substr($cleanPavilion, 0, 999);

        return Seller::updateOrCreate(
            ['slug' => $slug],
            [
                'name' => mb_substr($sellerData['name'], 0, 499),
                'source_url' => $sellerData['url'] ?? null,
                'pavilion' => $cleanPavilion ?: null,
                'pavilion_line' => $pavilionLine,
                'pavilion_number' => $pavilionNumber,
                'description' => mb_substr($sellerData['description'] ?? '', 0, 5000) ?: null,
                'phone' => mb_substr($sellerData['contacts']['phone'] ?? '', 0, 49) ?: null,
                'whatsapp_url' => $whatsappUrl,
                'whatsapp_number' => $whatsappNumber,
                'external_shop_id' => $shopId,
                'last_parsed_at' => now(),
            ]
        );
    }

    // -------------------------------------------------------------------------
    // HELPERS
    // -------------------------------------------------------------------------

    private function updateJob(array $data): void
    {
        $this->job->update($data);
    }

    private function updateAction(string $action): void
    {
        $this->job->update(['current_action' => $action]);
    }

    private function isCancelled(): bool
    {
        $this->job->refresh();
        return $this->job->status === 'cancelled';
    }

    private function log(string $level, string $message, array $context = []): void
    {
        ParserLog::write($level, $message, $context, $this->job->id);
    }

    private function extractSlug(string $url): string
    {
        if (preg_match('#/catalog/([a-z0-9\-]+)#', $url, $m)) {
            return $m[1];
        }
        if (preg_match('#/s/([a-z0-9\-]+)#', $url, $m)) {
            return $m[1];
        }
        return '';
    }
}
