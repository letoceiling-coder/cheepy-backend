<?php

namespace App\Jobs;

use App\Models\ParserJob;
use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class DownloadProductPhotosJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 3;

    public function __construct(
        public int $productId,
        public ?int $parserJobId = null
    ) {
        $this->onQueue('photos');
    }

    public function handle(): void
    {
        $product = Product::find($this->productId);
        if (! $product) {
            Log::warning('DownloadProductPhotosJob: Product not found', ['product_id' => $this->productId]);
            return;
        }

        $photos = is_array($product->photos) ? $product->photos : [];
        if ($photos === []) {
            return;
        }

        $maxQueueSize = max(100, (int) config('parser.max_photo_queue_size', 3000));
        $ratePerSec = max(1, (int) config('parser.micro_dispatch_rate_per_sec', 20));
        $maxPerProduct = max(1, (int) config('parser.micro_max_single_per_product', 24));
        $priorityEnabled = (bool) config('parser.enable_priority_queues', false);

        $queueLen = $this->photoQueueLength($priorityEnabled);
        if ($queueLen >= $maxQueueSize) {
            Log::warning('photo-micro dispatch skipped: queue full', [
                'product_id' => $product->id,
                'queue_len' => $queueLen,
                'max_photo_queue_size' => $maxQueueSize,
            ]);
            return;
        }

        $dispatched = 0;
        foreach ($photos as $index => $photoUrl) {
            if ($dispatched >= $maxPerProduct) {
                break;
            }

            $queueLen = $this->photoQueueLength($priorityEnabled);
            if ($queueLen >= $maxQueueSize) {
                Log::warning('photo-micro dispatch truncated: queue reached limit', [
                    'product_id' => $product->id,
                    'dispatched' => $dispatched,
                    'queue_len' => $queueLen,
                    'max_photo_queue_size' => $maxQueueSize,
                ]);
                break;
            }

            $delayMs = (int) floor(($dispatched * 1000) / $ratePerSec);
            $queue = $this->resolveSinglePhotoQueue($priorityEnabled, $index, (bool) $product->photos_downloaded);
            DownloadSinglePhotoJob::dispatch($product->id, (string) $photoUrl, (int) $index, $this->parserJobId)
                ->onQueue($queue)
                ->delay(now()->addMilliseconds($delayMs));
            $dispatched++;
        }
    }

    private function photoQueueLength(bool $priorityEnabled): int
    {
        if (! $priorityEnabled) {
            return (int) Redis::llen('queues:photos');
        }

        $queues = [
            (string) config('parser.photos_queue_high', 'photos_high'),
            (string) config('parser.photos_queue_normal', 'photos_normal'),
            (string) config('parser.photos_queue_low', 'photos_low'),
            'photos',
        ];

        $sum = 0;
        foreach (array_unique($queues) as $queue) {
            $sum += (int) Redis::llen('queues:' . $queue);
        }

        return $sum;
    }

    private function resolveSinglePhotoQueue(bool $priorityEnabled, int $index, bool $alreadyDownloaded): string
    {
        if (! $priorityEnabled) {
            return 'photos';
        }

        if (! $alreadyDownloaded && $index < 2) {
            return (string) config('parser.photos_queue_high', 'photos_high');
        }

        if ($index < 4) {
            return (string) config('parser.photos_queue_normal', 'photos_normal');
        }

        return (string) config('parser.photos_queue_low', 'photos_low');
    }
}

