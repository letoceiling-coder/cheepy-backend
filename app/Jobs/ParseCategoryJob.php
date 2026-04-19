<?php

namespace App\Jobs;

use App\Models\Category;
use App\Models\ParserJob;
use App\Services\DatabaseParserService;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

class ParseCategoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    /**
     * 2 попытки: один транзитный сбой сети больше не валит всю категорию.
     * Дополнительный локальный ретрай отдельной страницы внутри
     * DatabaseParserService::fetchCategoryPageWithRetry поднимает шансы пройти.
     */
    public int $tries = 2;

    /**
     * Backoff между попытками джоба (секунды). Нумерация — по индексу попытки.
     */
    public array $backoff = [60, 300];

    public function __construct(
        public int $parserJobId,
        public int $categoryId
    ) {
        $this->onQueue('parser');
    }

    public function handle(): void
    {
        $job = ParserJob::find($this->parserJobId);
        if (! $job) {
            return;
        }

        $category = Category::find($this->categoryId);
        if (! $category) {
            return;
        }

        $options = is_array($job->options) ? $job->options : [];
        $categorySlug = $category->external_slug ?? $category->slug ?? '';
        Log::warning('CATEGORY START', [
            'category' => $categorySlug,
            'max_pages' => $options['max_pages'] ?? null,
            'parser_job_id' => $job->id,
            'category_id' => $category->id,
        ]);

        $service = new DatabaseParserService($job);
        try {
            $service->runCategoryPipeline($category);
            Log::warning('CATEGORY DONE', [
                'category' => $categorySlug,
                'parser_job_id' => $job->id,
                'category_id' => $category->id,
            ]);
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'CRITICAL:')) {
                Log::critical('JOB HARD FAILED — NO RETRY', [
                    'parser_job_id' => $this->parserJobId,
                    'error' => $e->getMessage(),
                ]);

                $this->fail($e);

                return;
            }

            throw $e;
        }
    }
}
