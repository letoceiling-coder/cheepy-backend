<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\PhotoDownloadService;
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
            // Раньше: $photoService->downloadBatch(['limit'=>100]) — несоответствие сигнатуре
            // (downloadBatch ждёт iterable Products, а не массив опций) + неверный namespace
            // SadovodParser\PhotoDownloadService → каждый запуск падал ReflectionException.
            $query = Product::query()
                ->where('photos_downloaded', false)
                ->where('photos_count', '>', 0);

            if ($this->productId !== null) {
                $query->where('id', $this->productId);
            }

            $limit = $this->limit ?? 100;
            $products = $query->orderBy('id')->limit($limit)->get();

            if ($products->isEmpty()) {
                return;
            }

            $photoService->downloadBatch($products);
        } catch (\Throwable $e) {
            Log::error('DownloadPhotosJob failed: ' . $e->getMessage(), [
                'limit' => $this->limit,
                'product_id' => $this->productId,
            ]);
            throw $e;
        }
    }
}
