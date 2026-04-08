<?php

namespace App\Jobs;

use App\Services\SadovodParser\PhotoDownloadService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Queue job to download product photos.
 * Queue: photos
 */
class DownloadPhotosJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800; // 30 min
    public int $tries = 5;
    public array $backoff = [30, 120, 300, 600, 900];

    public function __construct(
        public ?int $limit = null,
        public ?int $productId = null
    ) {
        $this->onQueue('photos');
    }

    public function handle(PhotoDownloadService $photoService): void
    {
        try {
            $opts = array_filter([
                'limit' => $this->limit,
                'product_id' => $this->productId,
            ]);
            $photoService->downloadBatch($opts);
        } catch (\Throwable $e) {
            Log::error('DownloadPhotosJob failed: ' . $e->getMessage(), [
                'limit' => $this->limit,
                'product_id' => $this->productId,
            ]);
            throw $e;
        }
    }
}
