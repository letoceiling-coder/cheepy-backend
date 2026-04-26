<?php

namespace App\Jobs;

use App\Models\ParserJob;
use App\Models\Product;
use App\Services\PhotoDownloadService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class DownloadSinglePhotoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;

    public int $tries = 3;

    public array $backoff = [5, 15, 30];

    public function __construct(
        public int $productId,
        public string $photoUrl,
        public int $photoIndex,
        public ?int $parserJobId = null
    ) {
        $this->timeout = max(10, (int) config('parser.photo_job_timeout_seconds', 30));
        $this->onQueue('photos');
    }

    public function handle(PhotoDownloadService $photoService): void
    {
        $maxQueueSize = max(100, (int) config('parser.max_photo_queue_size', 3000));
        $queueLen = (int) Redis::llen('queues:photos');
        if ($queueLen >= $maxQueueSize) {
            Log::warning('photo-micro single skipped: queue full', [
                'product_id' => $this->productId,
                'photo_index' => $this->photoIndex,
                'queue_len' => $queueLen,
                'max_photo_queue_size' => $maxQueueSize,
            ]);
            return;
        }

        $product = Product::find($this->productId);
        if (! $product) {
            Log::warning('DownloadSinglePhotoJob: Product not found', ['product_id' => $this->productId]);
            return;
        }

        $startedAt = microtime(true);
        $result = $photoService->downloadSinglePhoto($product, $this->photoUrl, $this->photoIndex);
        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($this->parserJobId) {
            $job = ParserJob::find($this->parserJobId);
            if ($job) {
                if ($result['success']) {
                    $job->increment('photos_downloaded');
                } else {
                    $job->increment('photos_failed');
                }
            }
        }

        if (($result['skipped'] ?? false) === true) {
            Log::info('photo-micro single skipped', [
                'product_id' => $this->productId,
                'photo_index' => $this->photoIndex,
                'elapsed_ms' => $elapsedMs,
            ]);
        }
    }
}

