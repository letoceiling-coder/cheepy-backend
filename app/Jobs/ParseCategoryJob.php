<?php

namespace App\Jobs;

use App\Events\ParserFinished;
use App\Models\Category;
use App\Models\ParserJob;
use App\Models\ParserLog;
use App\Services\SadovodParser\HttpClient;
use App\Services\SadovodParser\Parsers\CatalogParser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ParseCategoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 2;

    public function __construct(
        public int $parserJobId,
        public int $categoryId
    ) {
        $this->onQueue('parser');
    }

    public function handle(): void
    {
        $job = ParserJob::find($this->parserJobId);
        if (!$job) {
            Log::warning('ParseCategoryJob: ParserJob not found', ['id' => $this->parserJobId]);
            return;
        }

        if ($this->isCancelled($job)) {
            Log::info('ParseCategoryJob: job cancelled', ['parser_job_id' => $this->parserJobId]);
            return;
        }

        $category = Category::find($this->categoryId);
        if (!$category) {
            Log::warning('ParseCategoryJob: Category not found', ['id' => $this->categoryId]);
            return;
        }

        $slug = $category->external_slug ?: $category->slug;
        $options = $job->options ?? [];
        $maxPages = $options['max_pages'] ?? $category->parser_max_pages ?? 0;
        $productLimit = $options['products_per_category'] ?? $category->parser_products_limit ?? 0;
        $savePhotos = $options['save_photos'] ?? false;
        $saveDetails = !($options['no_details'] ?? false);

        $config = config('sadovod');
        $http = new HttpClient(array_merge($config, config('parser_rate', [])));
        $catalogParser = new CatalogParser($http);

        $catalogPath = '/catalog/' . $slug;
        $page = 1;
        $savedCount = 0;

        $job->update([
            'current_action' => "Категория: {$slug}",
            'current_category_slug' => $slug,
        ]);

        ParserLog::write('info', "ParseCategoryJob started: {$slug}", [
            'category_slug' => $slug,
            'category_id' => $this->categoryId,
            'parser_job_id' => $this->parserJobId,
        ], $this->parserJobId);

        while (true) {
            if ($this->isCancelled($job)) {
                break;
            }

            $job->update(['current_page' => $page]);

            try {
                $result = $catalogParser->parseCategoryPage($catalogPath, $page);
                $products = $result['products'] ?? [];
                $hasMore = $result['has_more'] ?? false;

                if (empty($products)) {
                    break;
                }

                if ($page === 1 && isset($result['total_pages']) && $result['total_pages']) {
                    $job->update(['total_pages' => $result['total_pages']]);
                }

                // Batch dispatch: max 50 per chunk with a 200ms pause between chunks.
                // Prevents queue explosion when a category has thousands of products.
                $batchSize = (int) config('sadovod.dispatch_batch_size', 50);
                $chunks = array_chunk($products, $batchSize);

                foreach ($chunks as $chunk) {
                    if ($this->isCancelled($job)) {
                        break 2;
                    }
                    if ($productLimit > 0 && $savedCount >= $productLimit) {
                        break 2;
                    }

                    foreach ($chunk as $pData) {
                        if ($productLimit > 0 && $savedCount >= $productLimit) {
                            break 3;
                        }
                        ParseProductJob::dispatch(
                            $this->parserJobId,
                            $pData,
                            $this->categoryId,
                            [
                                'save_details' => $saveDetails,
                                'save_photos' => $savePhotos,
                            ]
                        );
                        $savedCount++;
                    }

                    // Pause between batches to let workers drain the queue
                    usleep(200_000); // 200ms
                }

                if (!$hasMore || ($maxPages > 0 && $page >= $maxPages)) {
                    break;
                }
                if ($productLimit > 0 && $savedCount >= $productLimit) {
                    break;
                }

                $page++;
                usleep((int) (config('sadovod.request_delay_ms', 200) * 1000));
            } catch (\Throwable $e) {
                ParserLog::write('error', "Ошибка парсинга страницы {$page} категории {$slug}: " . $e->getMessage(), [
                    'category_slug' => $slug,
                    'page' => $page,
                    'parser_job_id' => $this->parserJobId,
                ], $this->parserJobId);
                $job->increment('errors_count');
                Log::error('ParseCategoryJob page error', [
                    'category' => $slug,
                    'page' => $page,
                    'error' => $e->getMessage(),
                ]);
                break;
            }
        }

        $category->update([
            'products_count' => $savedCount,
            'last_parsed_at' => now(),
        ]);

        ParserLog::write('info', "Категория {$slug}: поставлено в очередь {$savedCount} товаров", [
            'category_slug' => $slug,
            'products_dispatched' => $savedCount,
        ], $this->parserJobId);

        $job->increment('parsed_categories');
        $job->refresh();

        if ($job->parsed_categories >= $job->total_categories) {
            $job->update([
                'status' => 'completed',
                'finished_at' => now(),
                'current_action' => 'Завершено',
            ]);
            $job->refresh();
            event(new ParserFinished($job));
        }
    }

    private function isCancelled(ParserJob $job): bool
    {
        $job->refresh();
        return in_array($job->status, ['cancelled', 'stopped'], true);
    }
}
