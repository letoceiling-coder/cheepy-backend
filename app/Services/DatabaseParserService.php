<?php

namespace App\Services;

use App\Events\ParserError;
use App\Events\ParserFinished;
use App\Events\ParserProgressUpdated;
use App\Events\ParserStarted;
use App\Events\ProductParsed;
use App\Models\Category;
use App\Models\ParserJob;
use App\Models\ParserProgress;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductPhoto;
use App\Models\Seller;
use App\Services\AttributeExtractionService;
use App\Services\SadovodParser\HttpClient;
use App\Services\SadovodParser\Parsers\CatalogParser;
use App\Services\SadovodParser\Parsers\MenuParser;
use App\Services\SadovodParser\Parsers\ProductParser;
use App\Jobs\DownloadPhotoJob;
use App\Jobs\ParseCategoryJob;
use App\Services\SadovodParser\Parsers\SellerParser;
use App\Services\Parser\ParserLogger;
use App\Support\ParserJobOptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

class DatabaseParserService
{
    private const PARSER_MEMORY_LIMIT_BYTES = 512 * 1024 * 1024;

    private HttpClient $http;
    private CatalogParser $catalogParser;
    private ProductParser $productParser;
    private SellerParser $sellerParser;
    private MenuParser $menuParser;
    private PhotoDownloadService $photoService;
    private ParserJob $job;

    private array $options;

    private string $optionsIntegrityHash;

    /** @var array<string, mixed> */
    private array $httpConfig;

    /** @var array{pages: int, pages_attempted: int, products: int} Per-category tallies (reset in parseCategoryPages). */
    private array $debugCounters = [
        'pages' => 0,
        'pages_attempted' => 0,
        'products' => 0,
    ];

    public function __construct(ParserJob $job)
    {
        $this->job = $job;
        $opts = $job->options;
        if (! is_array($opts)) {
            throw new \RuntimeException('CRITICAL: NO HTTP CONFIG IN OPTIONS');
        }
        if (! isset($opts['runtime']['http_client']) || ! is_array($opts['runtime']['http_client'])) {
            throw new \RuntimeException('CRITICAL: NO HTTP CONFIG IN OPTIONS');
        }
        ParserJobOptions::assertWorkerOptions($opts);
        $this->options = $opts;

        $this->assertCoreOptionsStrict();

        $this->httpConfig = $this->options['runtime']['http_client'];

        try {
            $normalized = $this->normalizeOptions($this->options);
            $optionsJson = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('CRITICAL: OPTIONS NOT JSON-SERIALIZABLE: '.$e->getMessage(), 0, $e);
        }
        $this->optionsIntegrityHash = md5($optionsJson);
        Log::critical('JOB OPTIONS HASH', [
            'job_id' => $this->job->id,
            'hash' => $this->optionsIntegrityHash,
        ]);

        Log::critical('OPTIONS VALIDATED', $this->options);
        Log::warning('JOB OPTIONS', ['job_id' => $this->job->id, 'options' => $this->options]);

        $this->http = new HttpClient($this->httpConfig);
        $this->catalogParser = new CatalogParser($this->http);
        $this->productParser = new ProductParser($this->http);
        $this->sellerParser = new SellerParser($this->http);
        $this->menuParser = new MenuParser($this->http);
        $this->photoService = new PhotoDownloadService();
    }

    /**
     * Fail fast: any missing option stops the worker with a loud error.
     *
     * @param  array<string, mixed>  $options
     * @return mixed
     */
    private function requireOption(array $options, string $key): mixed
    {
        if (! array_key_exists($key, $options)) {
            throw new RuntimeException("CRITICAL: missing option {$key}");
        }

        return $options[$key];
    }

    private function assertOptionsIntegrity(): void
    {
        try {
            $normalized = $this->normalizeOptions($this->options);
            $optionsJson = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('CRITICAL: OPTIONS NOT JSON-SERIALIZABLE: '.$e->getMessage(), 0, $e);
        }
        $currentHash = md5($optionsJson);
        if ($currentHash !== $this->optionsIntegrityHash) {
            throw new RuntimeException('CRITICAL: OPTIONS MUTATED');
        }
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function normalizeOptions(array $options): array
    {
        ksort($options);

        foreach ($options as $key => $value) {
            if (is_array($value)) {
                if (array_is_list($value)) {
                    $options[$key] = array_map(function ($v) {
                        return is_numeric($v) ? (int) $v : $v;
                    }, $value);

                    continue;
                }
                $options[$key] = $this->normalizeOptions($value);
            }
        }

        return $options;
    }

    private function assertCoreOptionsStrict(): void
    {
        $required = ['categories', 'linked_only', 'products_per_category', 'max_pages', 'no_details', 'save_photos', 'save_to_db'];
        foreach ($required as $key) {
            $this->requireOption($this->options, $key);
            if ($this->options[$key] === null) {
                throw new RuntimeException("CRITICAL: {$key} null");
            }
        }
        if (! is_array($this->options['categories'])) {
            throw new RuntimeException('CRITICAL: categories must be array');
        }
    }

    private function requestDelayMicros(): int
    {
        if (! array_key_exists('request_delay_ms', $this->httpConfig)) {
            throw new \RuntimeException('CRITICAL: http_client.request_delay_ms missing');
        }

        return max(1, (int) $this->httpConfig['request_delay_ms']) * 1000;
    }

    private function productBroadcastEvery(): int
    {
        if (! array_key_exists('product_broadcast_every', $this->httpConfig)) {
            throw new \RuntimeException('CRITICAL: http_client.product_broadcast_every missing');
        }

        return max(1, (int) $this->httpConfig['product_broadcast_every']);
    }

    private function donorBaseUrl(): string
    {
        if (! array_key_exists('base_url', $this->httpConfig)) {
            throw new \RuntimeException('CRITICAL: http_client.base_url missing');
        }

        return rtrim((string) $this->httpConfig['base_url'], '/');
    }

    /**
     * Запустить парсинг в соответствии с job->type
     */
    public function run(): void
    {
        $this->updateJob(['status' => 'running', 'started_at' => now()]);
        ParserProgress::updateOrCreate(
            ['job_id' => $this->job->id],
            ['total_items' => 0, 'processed_items' => 0, 'failed_items' => 0, 'current_url' => null]
        );
        $this->job->refresh();
        event(new ParserStarted($this->job));

        try {
            match ($this->job->type) {
                'menu_only' => $this->runMenuOnly(),
                'category'  => $this->runSingleCategory($this->options['category_slug'] ?? ''),
                'seller'    => $this->runSingleSeller($this->options['seller_slug'] ?? ''),
                default     => $this->runFullPipeline(),
            };

            $this->job->refresh();

            // For pipeline (full), completion is set by the last ParseCategoryJob
            if ($this->job->type !== 'full' || $this->job->total_categories <= 0) {
                $this->updateJob(['status' => 'completed', 'finished_at' => now()]);
                $this->job->refresh();
                event(new ParserFinished($this->job));
                $this->log('info', 'Парсинг завершён успешно', [
                    'products' => $this->job->saved_products,
                    'errors' => $this->job->errors_count,
                ]);
            } else {
                $this->log('info', 'Парсинг запущен (очередь категорий)', [
                    'total_categories' => $this->job->total_categories,
                ]);
            }
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
        $result = $this->menuParser->parse(null);
        $categories = $result['categories'] ?? [];
        $this->saveCategoriesFlat($categories);
        $this->log('info', 'Меню загружено', ['count' => count($categories)]);
    }

    /**
     * Save categories from flat list [{ name, slug, url, parent_slug }].
     */
    private function saveCategoriesFlat(array $items): void
    {
        $seen = [];
        $deduped = [];
        foreach ($items as $item) {
            $slug = $item['slug'] ?? $this->extractSlug($item['url'] ?? '');
            if (!$slug || isset($seen[$slug])) continue;
            $seen[$slug] = true;
            $deduped[] = $item;
        }
        $bySlug = [];
        foreach ($deduped as $item) {
            $slug = $item['slug'] ?? $this->extractSlug($item['url'] ?? '');
            if ($slug) $bySlug[$slug] = $item;
        }
        $ordered = [];
        $done = [];
        $addWithChildren = function (?string $parentSlug) use (&$addWithChildren, &$ordered, &$done, $bySlug) {
            foreach ($bySlug as $slug => $item) {
                if (isset($done[$slug])) continue;
                if (($item['parent_slug'] ?? null) !== $parentSlug) continue;
                $done[$slug] = true;
                $ordered[] = $item;
                $addWithChildren($slug);
            }
        };
        $addWithChildren(null);
        $slugToId = [];
        foreach ($ordered as $index => $cat) {
            $slug = $cat['slug'] ?? $this->extractSlug($cat['url'] ?? '');
            if (!$slug) continue;
            $parentId = null;
            $parentSlug = $cat['parent_slug'] ?? null;
            if ($parentSlug && isset($slugToId[$parentSlug])) {
                $parentId = $slugToId[$parentSlug];
            }
            $url = $cat['url'] ?? null;
            $category = Category::updateOrCreate(
                ['external_slug' => $slug],
                [
                    'name' => $cat['name'] ?? $cat['title'] ?? $slug,
                    'slug' => $slug,
                    'url' => $url,
                    'parent_id' => $parentId,
                    'sort_order' => $index,
                    'enabled' => true,
                ]
            );
            $slugToId[$slug] = $category->id;
        }
    }

    // -------------------------------------------------------------------------
    // FULL PARSE (queue pipeline: dispatch category jobs)
    // -------------------------------------------------------------------------

    /**
     * Pipeline mode: sync menu, then dispatch one ParseCategoryJob per category.
     * Completion is set by the last ParseCategoryJob when parsed_categories >= total_categories.
     */
    private function runFullPipeline(): void
    {
        // Меню донора меняется крайне редко. Раньше каждый full-проход (а демон может
        // запускаться раз в 60 секунд) делал десятки HTTP-запросов и сотни SQL-запросов
        // на updateOrCreate категорий. Кэшируем флаг «меню свежее» на 6 часов.
        $menuFreshKey = 'parser:menu_synced_at';
        if (! Cache::has($menuFreshKey) || Category::query()->doesntExist()) {
            $this->runMenuOnly();
            Cache::put($menuFreshKey, now()->toIso8601String(), now()->addHours(6));
        } else {
            Log::info('MENU SYNC SKIPPED (cache fresh)', [
                'parser_job_id' => $this->job->id,
                'last_synced_at' => Cache::get($menuFreshKey),
            ]);
        }

        $allowedCategories = $this->requireOption($this->options, 'categories');
        $linkedOnly = (bool) $this->requireOption($this->options, 'linked_only');
        Log::critical('CATEGORIES FILTER APPLIED', [
            'categories' => $allowedCategories,
            'parser_job_id' => $this->job->id,
        ]);
        Log::critical('CATEGORIES FILTER', [
            'allowed' => $allowedCategories,
        ]);

        $query = Category::where('enabled', true);

        if (! empty($allowedCategories)) {
            $ids = array_map('intval', array_filter($allowedCategories, 'is_numeric'));
            if (! empty($ids)) {
                $query->whereIn('id', $ids);
            } else {
                $query->whereIn('external_slug', $allowedCategories);
            }
        } elseif ($linkedOnly) {
            $query->where('linked_to_parser', true);
        }

        $categories = $query->orderBy('sort_order')->get();

        $categoriesToRun = [];
        foreach ($categories as $category) {
            if (! empty($allowedCategories)) {
                if (! $this->categoryMatchesJobAllowList($category, $allowedCategories)) {
                    Log::critical('CATEGORY SKIPPED', [
                        'category_id' => $category->id,
                    ]);
                    continue;
                }
            }
            $categoriesToRun[] = $category;
        }

        // Несколько выбранных категорий — нормальный сценарий: по одному ParseCategoryJob на категорию.
        // (Старый throw «CATEGORY FILTER IGNORED» ошибочно запрещал >1 категории при непустом фильтре.)

        $total = count($categoriesToRun);
        $this->updateJob(['total_categories' => $total]);

        if ($total === 0) {
            $this->updateJob(['status' => 'completed', 'finished_at' => now()]);
            $this->job->refresh();
            event(new ParserFinished($this->job));
            $this->log('info', 'Нет категорий для парсинга');
            return;
        }

        foreach ($categoriesToRun as $category) {
            if ($this->isCancelled()) {
                break;
            }
            ParseCategoryJob::dispatch($this->job->id, $category->id);
        }

        $this->log('info', 'Поставлено в очередь категорий: ' . $total, [
            'total_categories' => $total,
        ]);
    }

    /**
     * @param  array<int, mixed>  $allowedCategories
     */
    private function categoryMatchesJobAllowList(Category $category, array $allowedCategories): bool
    {
        $allowedIds = array_values(array_unique(array_map('intval', array_filter($allowedCategories, 'is_numeric'))));
        if ($allowedIds !== []) {
            return in_array((int) $category->id, $allowedIds, true);
        }

        $slugs = array_values(array_filter($allowedCategories, static fn ($v) => is_string($v) && $v !== ''));

        return in_array((string) ($category->external_slug ?? ''), $slugs, true);
    }

    /**
     * Sequential full parse (legacy, used for single-category or when not using pipeline).
     */
    private function runFull(): void
    {
        // Меню — раз в 6 часов; см. комментарий в runFullPipeline().
        $menuFreshKey = 'parser:menu_synced_at';
        if (! Cache::has($menuFreshKey) || Category::query()->doesntExist()) {
            $this->runMenuOnly();
            Cache::put($menuFreshKey, now()->toIso8601String(), now()->addHours(6));
        }

        $categoryFilter = $this->requireOption($this->options, 'categories');
        $linkedOnly = (bool) $this->requireOption($this->options, 'linked_only');
        Log::critical('CATEGORIES FILTER APPLIED', [
            'categories' => $categoryFilter,
            'parser_job_id' => $this->job->id,
        ]);
        Log::warning('CATEGORIES SELECTED', [
            'categories' => $categoryFilter,
            'linked_only' => $linkedOnly,
            'parser_job_id' => $this->job->id,
        ]);

        $query = Category::where('enabled', true);

        if (! empty($categoryFilter)) {
            $ids = array_map('intval', array_filter($categoryFilter, 'is_numeric'));
            if (! empty($ids)) {
                $query->whereIn('id', $ids);
            } else {
                $query->whereIn('external_slug', $categoryFilter);
            }
        } elseif ($linkedOnly) {
            $query->where('linked_to_parser', true);
        }

        $categories = $query->orderBy('sort_order')->get();
        $this->updateJob(['total_categories' => $categories->count()]);

        foreach ($categories as $category) {
            if ($this->isCancelled()) break;
            $this->runSingleCategory($category->external_slug, $category);
        }
    }

    // -------------------------------------------------------------------------
    // SINGLE CATEGORY
    // -------------------------------------------------------------------------

    public function runCategoryPipeline(Category $category): void
    {
        $slug = $category->external_slug ?? '';
        if ($slug === '') {
            return;
        }

        $this->updateAction("Категория: {$slug}");
        $this->updateJob(['current_category_slug' => $slug]);

        $savedCount = $this->parseCategoryPages($category, true);

        $category->update([
            'products_count' => $savedCount,
            'last_parsed_at' => now(),
        ]);

        $this->log('info', "Категория {$slug}: сохранено {$savedCount} товаров (pipeline)");

        $this->job->increment('parsed_categories');
        $this->job->refresh();
        $this->maybeCompleteFullPipelineJob();
    }

    private function maybeCompleteFullPipelineJob(): void
    {
        if ($this->job->type !== 'full') {
            return;
        }
        $this->job->refresh();
        $total = (int) $this->job->total_categories;
        $done = (int) $this->job->parsed_categories;
        if ($total > 0 && $done >= $total) {
            $this->updateJob(['status' => 'completed', 'finished_at' => now()]);
            $this->job->refresh();
            event(new ParserFinished($this->job));
            $this->log('info', 'Парсинг завершён успешно (pipeline)', [
                'products' => $this->job->saved_products,
                'errors' => $this->job->errors_count,
            ]);
        }
    }

    private function parseCategoryPages(Category $category, bool $dispatchPhotosToQueue): int
    {
        $slug = $category->external_slug ?? '';
        if ($slug === '') {
            return 0;
        }

        $this->assertOptionsIntegrity();

        $this->debugCounters = [
            'pages' => 0,
            'pages_attempted' => 0,
            'products' => 0,
        ];

        $memory = memory_get_usage(true);
        if ($memory > self::PARSER_MEMORY_LIMIT_BYTES) {
            Log::critical('MEMORY LIMIT REACHED', [
                'memory' => $memory,
                'parser_job_id' => $this->job->id,
            ]);
            throw new RuntimeException('CRITICAL: MEMORY LIMIT');
        }

        $maxPagesRaw = $this->requireOption($this->options, 'max_pages');
        if ($maxPagesRaw === null) {
            throw new RuntimeException('CRITICAL: max_pages null');
        }
        $maxPages = (int) $maxPagesRaw;
        // UI: «0 = по полю категории» — берём лимит страниц из категории, если в джобе не задан.
        if ($maxPages <= 0) {
            $perCat = (int) ($category->parser_max_pages ?? 0);
            if ($perCat > 0) {
                $maxPages = $perCat;
            }
        }
        $productLimit = (int) $this->requireOption($this->options, 'products_per_category');
        $saveDetails = ! ((bool) $this->requireOption($this->options, 'no_details'));
        // По умолчанию true — старое поведение (full + update existing).
        // false — режим «только новые»: пропускаем external_id, которые уже есть в products,
        // экономим HTTP на детали товара (productParser->parse) и весь upsert/photos pipeline.
        $updateExisting = (bool) ($this->options['update_existing'] ?? true);

        $page = 1;
        $savedCount = 0;
        $pageFetchFailures = 0;
        $maxPageFetchRetries = 1;

        while (true) {
            if ($this->isCancelled()) {
                break;
            }

            // maxPages — мягкий потолок. Раньше тут стоял throw 'PAGE LIMIT VIOLATION',
            // из-за которого категория падала с CRITICAL на регулярном условии «достигли потолка».
            if ($maxPages > 0 && $page > $maxPages) {
                break;
            }

            $this->updateAction("Категория: {$slug} | Страница {$page}" . ($maxPages ? "/{$maxPages}" : ''));
            $this->updateJob(['current_page' => $page]);

            try {
                $this->debugCounters['pages_attempted']++;
                // Одна HTTP-страница каталога за итерацию + локальный ретрай на одиночную сетевую ошибку.
                // Раньше один таймаут страницы 1 валил весь обход категории.
                $result = $this->fetchCategoryPageWithRetry($slug, $page, $maxPageFetchRetries);
                $products = $result['products'] ?? [];
                $hasMore = $result['has_more'] ?? false;
                $crawlerHandled = true;

                if (empty($products)) {
                    Log::warning('EMPTY PAGE DETECTED', [
                        'category' => $slug,
                        'page' => $page,
                        'parser_job_id' => $this->job->id,
                    ]);
                    break;
                }

                if ($page === 1) {
                    $totalPages = $result['total_pages'] ?? 1;
                    if ($maxPages > 0) {
                        $totalPages = min($totalPages, $maxPages);
                    }
                    $this->updateJob(['total_pages' => $totalPages]);
                }

                // Режим «только новые»: одним SELECT WHERE IN отсекаем уже существующие external_id.
                // Для крупной страницы (50 товаров) это 1 SQL вместо N HTTP-запросов на детали + upsert.
                $existingIds = [];
                if (! $updateExisting) {
                    $pageIds = [];
                    foreach ($products as $pData) {
                        $eid = (string) ($pData['id'] ?? '');
                        if ($eid !== '') {
                            $pageIds[] = $eid;
                        }
                    }
                    if ($pageIds !== []) {
                        $existingIds = Product::whereIn('external_id', $pageIds)
                            ->pluck('external_id')
                            ->map(fn ($v) => (string) $v)
                            ->all();
                        $existingIds = array_flip($existingIds);
                    }
                }

                $skippedExisting = 0;
                foreach ($products as $pData) {
                    if ($this->isCancelled()) {
                        break 2;
                    }
                    if ($productLimit > 0 && $savedCount >= $productLimit) {
                        Log::warning('PRODUCT LIMIT REACHED', [
                            'category' => $slug,
                            'saved_count' => $savedCount,
                            'products_per_category' => $productLimit,
                            'parser_job_id' => $this->job->id,
                        ]);
                        break 2;
                    }

                    if (! $updateExisting) {
                        $eid = (string) ($pData['id'] ?? '');
                        if ($eid !== '' && isset($existingIds[$eid])) {
                            $skippedExisting++;
                            continue;
                        }
                    }

                    $saved = $this->saveProductFromListing($pData, $category, $saveDetails, $dispatchPhotosToQueue);
                    if ($saved) {
                        $savedCount++;
                        $this->debugCounters['products']++;
                        // Раньше здесь стоял $this->job->refresh() на каждый сохранённый товар
                        // (на крупной категории — тысячи лишних SELECT * FROM parser_jobs).
                        // Refresh теперь делаем только перед event'ом раз в N товаров.
                        if ($savedCount % 50 === 0) {
                            $this->job->refresh();
                            event(new ParserProgressUpdated($this->job));
                        }
                    }
                }

                if (! empty($products)) {
                    $this->debugCounters['pages']++;
                    Log::info('PAGE PARSED', [
                        'category' => $slug,
                        'page' => $page,
                        'parser_job_id' => $this->job->id,
                        'skipped_existing' => $skippedExisting,
                        'update_existing' => $updateExisting,
                    ]);
                }

                if ($maxPages > 0 && $page >= $maxPages) {
                    Log::warning('PAGE LIMIT REACHED', [
                        'category' => $slug,
                        'page' => $page,
                        'max_pages' => $maxPages,
                        'parser_job_id' => $this->job->id,
                        'reason' => 'max_pages_cap',
                    ]);
                }

                if (! $hasMore || ($maxPages > 0 && $page >= $maxPages)) {
                    break;
                }
                if ($productLimit > 0 && $savedCount >= $productLimit) {
                    Log::warning('PRODUCT LIMIT REACHED', [
                        'category' => $slug,
                        'saved_count' => $savedCount,
                        'products_per_category' => $productLimit,
                        'parser_job_id' => $this->job->id,
                        'where' => 'end_of_page',
                    ]);
                    break;
                }

                $page++;
                // Задержка между HTTP-запросами уже выполнена внутри
                // Parser\HttpClient::get (random delay_min_ms..delay_max_ms) и
                // SadovodParser\HttpClient::applyRateLimit (max_requests_per_second).
                // Дополнительный sleep здесь раньше суммировался с этими двумя и
                // давал 3-кратное замедление прохода категории.
            } catch (\Throwable $e) {
                $this->log('error', "Ошибка парсинга страницы {$page} категории {$slug}: " . $e->getMessage());
                $this->job->increment('errors_count');
                event(new ParserError($this->job, "Ошибка парсинга страницы {$page} категории {$slug}: " . $e->getMessage()));
                $pageFetchFailures++;
                if ($pageFetchFailures >= 3) {
                    Log::warning('CATEGORY ABORTED AFTER FAILURES', [
                        'category' => $slug,
                        'failures' => $pageFetchFailures,
                        'parser_job_id' => $this->job->id,
                    ]);
                    break;
                }
                $page++;
            } finally {
                if (isset($result)) {
                    unset($result);
                }
                gc_collect_cycles();
            }

            $memory = memory_get_usage(true);
            if ($memory > self::PARSER_MEMORY_LIMIT_BYTES) {
                Log::critical('MEMORY LIMIT REACHED MID-CATEGORY', [
                    'category' => $slug,
                    'page' => $page,
                    'memory' => $memory,
                    'parser_job_id' => $this->job->id,
                ]);
                throw new RuntimeException('CRITICAL: MEMORY LIMIT');
            }
        }

        // Раньше тут были throw 'PAGE LIMIT BROKEN' / 'PRODUCT LIMIT BROKEN' / 'CATEGORY FAILED' —
        // это были не критические инварианты, а нормальный конец прохода. Категория с пустыми
        // страницами или достигшая лимита больше не считается ошибкой пайплайна.

        Log::warning('CATEGORY RESULT', [
            'category' => $slug,
            'pages_processed' => $this->debugCounters['pages'],
            'pages_attempted' => $this->debugCounters['pages_attempted'],
            'products_processed' => $this->debugCounters['products'],
            'parser_job_id' => $this->job->id,
        ]);

        return $savedCount;
    }

    private function runSingleCategory(string $slug, ?Category $category = null): void
    {
        if (! $category) {
            $category = Category::where('external_slug', $slug)->first();
        }
        if (! $category) {
            return;
        }

        $this->updateAction("Категория: {$slug}");
        $this->updateJob(['current_category_slug' => $slug]);

        $savedCount = $this->parseCategoryPages($category, false);

        $category->update([
            'products_count' => $savedCount,
            'last_parsed_at' => now(),
        ]);

        $this->job->increment('parsed_categories');
        $this->job->refresh();

        $this->log('info', "Категория {$slug}: сохранено {$savedCount} товаров");
    }

    // -------------------------------------------------------------------------
    // PRODUCT
    // -------------------------------------------------------------------------

    /**
     * Save product from listing data. When $dispatchPhotosToQueue is true (queue pipeline),
     * photo records are created and DownloadPhotoJob is dispatched instead of downloading inline.
     * Photo handling is gated by options.save_photos (SSOT).
     */
    public function saveProductFromListing(array $pData, ?Category $category, bool $saveDetails, bool $dispatchPhotosToQueue = false): bool
    {
        try {
            $externalId = (string) ($pData['id'] ?? '');
            if (!$externalId) return false;

            // Уточнённые детали с отдельной страницы товара
            if ($saveDetails) {
                try {
                    $detailData = $this->productParser->parse('/odejda/' . $externalId);
                    $pData = array_merge($pData, $detailData);
                    // sleep уже сделан в нижнем HTTP-клиенте, дополнительный убран.
                } catch (\Throwable $e) {
                    $this->log('warn', "Не удалось получить детали товара {$externalId}: " . $e->getMessage(), [
                        'product_external_id' => $externalId,
                        'job_id' => $this->job->id,
                    ]);
                }
            }

            $title = trim((string) ($pData['title'] ?? ''));
            $price = $pData['price'] ?? null;

            $existingProduct = Product::where('external_id', $externalId)->first();
            if ($existingProduct) {
                if (mb_strlen($title) < 3) {
                    $title = trim((string) ($existingProduct->title ?? ''));
                }
                if ($price === null || $price === '') {
                    $price = $existingProduct->price;
                }
            }

            $pData['title'] = $title;
            $pData['price'] = $price;

            if ($title === '') {
                throw new RuntimeException('INVALID PRODUCT: NO TITLE');
            }
            if (! isset($price) || $price === null || $price === '') {
                throw new RuntimeException('INVALID PRODUCT: NO PRICE');
            }
            if (mb_strlen($title) < 3) {
                throw new RuntimeException('INVALID PRODUCT: SHORT TITLE');
            }

            // Продавец: по slug с продукта — переиспользуем или парсим страницу /s/{slug}
            $seller = $this->getOrCreateSellerForProduct($pData['seller'] ?? []);

            // Сохраняем продукт (updateOrCreate по external_id внутри upsertFromParser)
            $product = Product::upsertFromParser($pData, $category?->id, $seller?->id);
            $isNew = $product->wasRecentlyCreated;
            Log::warning('PRODUCT UPSERT', [
                'external_id' => $externalId,
                'is_new' => $isNew,
            ]);

            $product->syncSimilarFromParser($pData['similar_external_ids'] ?? []);

            if ($seller) {
                $seller->increment('products_count');
            }

            // Нормализованные атрибуты — через AttributeExtractionService (rules from DB)
            try {
                app(AttributeExtractionService::class)->extractAndSave($product);
            } catch (\Throwable $e) {
                // Fallback to legacy extraction if service fails
                $this->saveProductAttributes($product, $pData['characteristics'] ?? [], $category);
                Log::warning('AttributeExtractionService failed, used legacy', ['error' => $e->getMessage()]);
            }

            // Always persist core filter attributes (color/size) even if rule-based extraction misses them.
            $this->syncCoreAttributes($product, $pData['characteristics'] ?? [], $category);

            $savePhotosOpt = (bool) $this->requireOption($this->options, 'save_photos');
            // Новые флаги — фолбэк на save_photos для обратной совместимости со старыми options.
            $downloadPhotosOpt = (bool) ($this->options['download_photos'] ?? $savePhotosOpt);
            $storePhotoLinksOpt = (bool) ($this->options['store_photo_links'] ?? $savePhotosOpt);

            if (! $savePhotosOpt) {
                $key = 'photo_block_logged_job_'.$this->job->id;
                if (! Cache::get($key)) {
                    Log::info('PHOTO PIPELINE DISABLED (save_photos=false)', [
                        'parser_job_id' => $this->job->id,
                    ]);
                    Cache::put($key, true, 3600);
                }
            } elseif (! empty($pData['photos'])) {
                if ($downloadPhotosOpt) {
                    if ($dispatchPhotosToQueue) {
                        $this->createPhotoRecordsOnly($product, $pData['photos'] ?? []);
                        // Дедупликация: диспатчим только если есть фото в pending/failed.
                        // Иначе на повторном проходе одного и того же товара джоб
                        // делал бы лишнюю прокрутку воркера ради `skipped`.
                        $hasPending = $product->photoRecords()
                            ->whereIn('download_status', ['pending', 'failed'])
                            ->exists();
                        if ($hasPending) {
                            DownloadPhotoJob::dispatch($product->id, $this->job->id);
                        }
                    } else {
                        $result = $this->photoService->downloadProductPhotos($product);
                        $this->job->increment('photos_downloaded', $result['downloaded']);
                        $this->job->increment('photos_failed', $result['failed']);
                    }
                } elseif ($storePhotoLinksOpt) {
                    // Только ссылки: создаём ProductPhoto со статусом pending без скачивания.
                    // Раньше при store_photo_links=true ничего не происходило, потому что флаг был неактивен.
                    $this->createPhotoRecordsOnly($product, $pData['photos'] ?? []);
                }
            }

            $this->job->increment('saved_products');
            $this->job->increment('parsed_products');
            $this->updateProgress(null, 1, 0);
            $broadcastEvery = $this->productBroadcastEvery();
            // Без refresh() значение в локальной модели всё равно валидное
            // (увеличение через increment() обновляет атрибут). На большом проходе
            // отказ от refresh() экономит сотни SELECT * FROM parser_jobs.
            if (((int) $this->job->parsed_products % $broadcastEvery) === 0) {
                event(new ProductParsed($this->job, [
                    'id' => $product->id,
                    'external_id' => $product->external_id,
                    'title' => $product->title ?? $pData['title'] ?? '',
                ]));
            }
            return true;
        } catch (\Throwable $e) {
            $this->log('error', "Ошибка сохранения товара: " . $e->getMessage(), [
                'data' => $pData['id'] ?? '',
                'product_external_id' => $pData['id'] ?? null,
                'job_id' => $this->job->id,
            ]);
            if (isset($product) && $product instanceof Product) {
                Product::markParseErrorIfRunning($product, $e);
            } elseif (!empty($pData['id'])) {
                $existing = Product::where('external_id', (string) $pData['id'])->first();
                if ($existing) {
                    Product::markParseErrorIfRunning($existing, $e);
                }
            }
            $this->job->increment('errors_count');
            $this->updateProgress($pData['url'] ?? null, 0, 1);
            // refresh() не нужен — локальная модель уже отражает инкремент.
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

    private function syncCoreAttributes(Product $product, array $characteristics, ?Category $category): void
    {
        $color = $characteristics['color'] ?? $characteristics['Цвет'] ?? $product->color ?? null;
        $size = $characteristics['size'] ?? $characteristics['Размер'] ?? $characteristics['size_range'] ?? $product->size_range ?? null;

        $color = $this->normalizeCoreAttrValue($color);
        $size = $this->normalizeCoreAttrValue($size);

        if ($color !== null) {
            ProductAttribute::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'attr_name' => 'Цвет',
                    'attr_type' => 'color',
                ],
                [
                    'category_id' => $category?->id,
                    'attr_value' => $color,
                ]
            );
        }

        if ($size !== null) {
            ProductAttribute::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'attr_name' => 'Размер',
                    'attr_type' => 'size',
                ],
                [
                    'category_id' => $category?->id,
                    'attr_value' => $size,
                ]
            );
        }
    }

    private function normalizeCoreAttrValue(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        if ($value === '' || mb_strlen($value) > 200) {
            return null;
        }
        if (preg_match('/Добавить в корзину|Смотреть все|Позвонить/ui', $value)) {
            return null;
        }

        return mb_substr($value, 0, 190);
    }

    private function createPhotoRecordsOnly(Product $product, array $photos): void
    {
        if (empty($photos)) return;

        foreach ($photos as $index => $url) {
            $normalUrl = str_starts_with($url, 'http')
                ? $url
                : $this->donorBaseUrl() . '/' . ltrim($url, '/');

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

    /**
     * Resolve seller for a product: reuse by slug, or parse /s/{slug} and atomic upsert.
     * Uses Redis cache (seller:{slug}, 1h), lock to prevent race, 10s timeout, 3 retries.
     */
    private function getOrCreateSellerForProduct(array $sellerFromProduct): ?Seller
    {
        $slug = $sellerFromProduct['seller_slug'] ?? $sellerFromProduct['slug'] ?? null;
        if (!$slug || !is_string($slug) || mb_strlen($slug) < 3) {
            return null;
        }

        $seller = Cache::lock("seller:parse:{$slug}", 30)->get(function () use ($slug, $sellerFromProduct): ?Seller {
            $existing = Seller::where('slug', $slug)->first();
            if ($existing) {
                Log::info('Seller reused', ['slug' => $slug, 'parser_job_id' => $this->job->id]);
                $this->log('info', "Seller reused: {$slug}");
                return $existing;
            }

            $cacheKey = "seller:{$slug}";
            $data = Cache::get($cacheKey);

            if (!$data) {
                try {
                    $this->updateAction("Продавец: {$slug}");
                    // sleep уже сделан в нижнем HTTP-клиенте.
                    $data = $this->sellerParser->parse('/s/' . $slug);
                    Cache::put($cacheKey, $data, 3600);
                } catch (\Throwable $e) {
                    Log::warning('Seller parse failed', ['slug' => $slug, 'error' => $e->getMessage(), 'parser_job_id' => $this->job->id]);
                    $this->log('error', "Seller parse failed: {$slug} - " . $e->getMessage());
                    $this->job->increment('errors_count');
                    return null;
                }
            }

            if (empty($data['name']) && !empty($sellerFromProduct['seller_name'])) {
                $data['name'] = $this->normalizeSellerName($sellerFromProduct['seller_name']);
            }

            $seller = $this->upsertSeller($data);
            if ($seller) {
                Log::info('Seller created', ['slug' => $slug, 'parser_job_id' => $this->job->id]);
                $this->log('info', "Seller created: {$slug}");
            }
            return $seller;
        });

        // Cache::lock()->get() returns false when lock is not acquired.
        // Never propagate bool to keep strict ?Seller return type.
        if ($seller === false) {
            return Seller::where('slug', $slug)->first();
        }

        return $seller;
    }

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

    /**
     * UPSERT seller by slug. Expects data from seller page (/s/{slug}), not from product page.
     */
    private function upsertSeller(array $sellerData): ?Seller
    {
        $slug = $sellerData['slug'] ?? null;
        if (!$slug) {
            $slug = !empty($sellerData['name']) ? Str::slug($sellerData['name']) : null;
        }
        if (!$slug) {
            return null;
        }
        $name = $this->normalizeSellerName($sellerData['name'] ?? '');
        if (!$name) {
            return null;
        }

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

        $avatarUrl = $sellerData['avatar'] ?? null;
        if ($avatarUrl && !str_starts_with($avatarUrl, 'http')) {
            $avatarUrl = $this->donorBaseUrl() . '/' . ltrim($avatarUrl, '/');
        }

        return Seller::updateOrCreate(
            ['slug' => $slug],
            [
                'name' => mb_substr($name, 0, 499),
                'source_url' => $sellerData['url'] ?? null,
                'avatar_url' => $avatarUrl ? mb_substr($avatarUrl, 0, 499) : null,
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
        $this->updateProgress(null, 0, 0);
    }

    private function isCancelled(): bool
    {
        $this->job->refresh();
        return in_array($this->job->status, ['cancelled', 'stopped'], true);
    }

    private function normalizeSellerName(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/^[,"\']+/u', '', $name);
        $name = preg_replace('/[,"\']+$/u', '', $name);
        return trim($name);
    }

    private function log(string $level, string $message, array $context = []): void
    {
        $type = match ($level) {
            'error' => 'parsing_error',
            'warning', 'warn' => 'timeout',
            default => 'info',
        };

        ParserLogger::write(
            $type,
            $message,
            $context,
            $this->job->id,
            'Parser'
        );
    }

    private function updateProgress(?string $currentUrl = null, int $processedDelta = 0, int $failedDelta = 0): void
    {
        $row = ParserProgress::firstOrNew(['job_id' => $this->job->id]);
        $row->total_items = (int) ($this->job->total_products ?: $this->job->total_categories);
        $row->processed_items = max(0, (int) $row->processed_items + $processedDelta);
        $row->failed_items = max(0, (int) $row->failed_items + $failedDelta);
        $shouldUpdateSpeed = $row->processed_items === 0 || $row->processed_items % 10 === 0;
        if ($shouldUpdateSpeed) {
            $startedAt = $this->job->started_at ?? $this->job->created_at;
            $elapsedMinutes = $startedAt ? max(1 / 60, now()->diffInSeconds($startedAt) / 60) : 1;
            $row->speed_per_min = (int) round($row->processed_items / $elapsedMinutes);
        }
        if ($currentUrl !== null) {
            $row->current_url = mb_substr($currentUrl, 0, 990);
        }
        $row->save();
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

    /**
     * Один лёгкий локальный ретрай страницы каталога. Один разовый таймаут донора больше не валит категорию.
     *
     * @return array{products: array, has_more: bool, total_pages: int|null}
     */
    private function fetchCategoryPageWithRetry(string $slug, int $page, int $retries): array
    {
        $attempt = 0;
        $lastError = null;
        do {
            try {
                return $this->catalogParser->parseCategoryPage('/catalog/' . $slug, $page);
            } catch (\Throwable $e) {
                $lastError = $e;
                $attempt++;
                if ($attempt > $retries) {
                    break;
                }
                Log::warning('CATEGORY PAGE RETRY', [
                    'category' => $slug,
                    'page' => $page,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                    'parser_job_id' => $this->job->id,
                ]);
                sleep(2 * $attempt);
            }
        } while ($attempt <= $retries);

        throw $lastError ?? new RuntimeException('CATEGORY PAGE FETCH FAILED');
    }
}
