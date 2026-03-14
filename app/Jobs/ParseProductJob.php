<?php

namespace App\Jobs;

use App\Models\Category;
use App\Models\ParserJob;
use App\Models\ParserLog;
use App\Services\DatabaseParserService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ParseProductJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 2;

    public function __construct(
        public int $parserJobId,
        public array $productData,
        public int $categoryId,
        public array $options = []
    ) {
        // Keep product processing off the category queue to avoid parser starvation.
        $this->onQueue((string) config('sadovod.product_queue', 'photos'));
    }

    public function handle(): void
    {
        $job = ParserJob::find($this->parserJobId);
        if (!$job) {
            Log::warning('ParseProductJob: ParserJob not found', ['id' => $this->parserJobId]);
            $this->logOrphanOnce('ParseProductJob', 'product');
            return;
        }

        // Не прерываем по статусу job: задача уже в очереди — сохраняем товар до конца.
        // isCancelled проверяется только в ParseCategoryJob (прекращение постановки новых задач).
        $category = Category::find($this->categoryId);
        $saveDetails = $this->options['save_details'] ?? true;
        $savePhotos = $this->options['save_photos'] ?? false;

        $service = new DatabaseParserService($job);
        $result = $service->saveProductFromListing(
            $this->productData,
            $category,
            $saveDetails,
            $savePhotos,
            true
        );

        if ($result === false) {
            Log::debug('Product skipped', [
                'product_external_id' => $this->productData['external_id'] ?? $this->productData['id'] ?? null,
                'category_id' => $this->categoryId,
                'parser_job_id' => $this->parserJobId,
            ]);
        }
    }

    private function logOrphanOnce(string $jobType, string $entityType): void
    {
        $key = "parser:orphan_logged:{$this->parserJobId}";
        try {
            if (\Illuminate\Support\Facades\Redis::set($key, 1, 'EX', 3600, 'NX')) {
                ParserLog::write('error',
                    "Орфанная задача: ParserJob #{$this->parserJobId} не найден. Товары не сохраняются. Выполните «Сброс системы», затем «Запустить».",
                    ['job_type' => $jobType, 'parser_job_id' => $this->parserJobId],
                    null, 'Parser', $entityType, (string) ($this->productData['id'] ?? '')
                );
            }
        } catch (\Throwable $e) {
            // ignore Redis/log failures
        }
    }
}
