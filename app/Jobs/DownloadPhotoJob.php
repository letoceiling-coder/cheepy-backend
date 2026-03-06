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

class DownloadPhotoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 2;

    public function __construct(
        public int $productId,
        public ?int $parserJobId = null
    ) {
        $this->onQueue('photos');
    }

    public function handle(PhotoDownloadService $photoService): void
    {
        $product = Product::find($this->productId);
        if (!$product) {
            Log::warning('DownloadPhotoJob: Product not found', ['product_id' => $this->productId]);
            return;
        }

        $result = $photoService->downloadProductPhotos($product);

        if ($this->parserJobId) {
            $job = ParserJob::find($this->parserJobId);
            if ($job) {
                $job->increment('photos_downloaded', $result['downloaded']);
                $job->increment('photos_failed', $result['failed']);
            }
        }

        $product->refresh();
        $photosCount = $product->photoRecords()->where('download_status', 'done')->count();
        $product->update(['photos_count' => $photosCount]);
    }
}
