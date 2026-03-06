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
        $this->onQueue('parser');
    }

    public function handle(): void
    {
        $job = ParserJob::find($this->parserJobId);
        if (!$job) {
            Log::warning('ParseProductJob: ParserJob not found', ['id' => $this->parserJobId]);
            return;
        }

        if ($this->isCancelled($job)) {
            return;
        }

        $category = Category::find($this->categoryId);
        $saveDetails = $this->options['save_details'] ?? true;
        $savePhotos = $this->options['save_photos'] ?? false;

        $externalId = $this->productData['external_id'] ?? $this->productData['id'] ?? null;
        Log::info('ParseProductJob started', ['external_id' => $externalId]);

        $service = new DatabaseParserService($job);
        $result = $service->saveProductFromListing(
            $this->productData,
            $category,
            $saveDetails,
            $savePhotos,
            true
        );

        Log::info('Product save result', [
            'external_id' => $externalId,
            'saved' => $result,
        ]);

        if ($result === false) {
            Log::warning('Product skipped', ['external_id' => $externalId]);
        }

        ParserLog::write('debug', 'ParseProductJob completed', [
            'product_external_id' => $this->productData['id'] ?? null,
            'category_id' => $this->categoryId,
            'parser_job_id' => $this->parserJobId,
        ], $this->parserJobId, 'Parser', 'product', (string) ($this->productData['id'] ?? ''));
    }

    private function isCancelled(ParserJob $job): bool
    {
        $job->refresh();
        return in_array($job->status, ['cancelled', 'stopped'], true);
    }
}
