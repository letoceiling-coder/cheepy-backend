<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\ProductSource;
use App\Models\SystemProduct;
use App\Services\Catalog\SystemProductFromDonorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Создаёт system_product для нового донорного товара, если связи ещё нет.
 * Ошибки job не откатывают парсер — только лог.
 */
class EnsureSystemProductFromDonorJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60, 120];

    public function __construct(
        public int $donorProductId
    ) {}

    public function uniqueId(): string
    {
        return 'ensure-system-product-donor-'.$this->donorProductId;
    }

    public function handle(SystemProductFromDonorService $fromDonorService): void
    {
        if (! filter_var(env('CATALOG_AUTO_INGEST_FROM_PARSER', true), FILTER_VALIDATE_BOOL)) {
            return;
        }

        $product = Product::query()->find($this->donorProductId);
        if ($product === null) {
            return;
        }

        DB::transaction(function () use ($product, $fromDonorService): void {
            Product::query()->whereKey($product->id)->lockForUpdate()->first();

            $exists = ProductSource::query()
                ->where('donor_product_id', $product->id)
                ->where('source', ProductSource::SOURCE_PARSER)
                ->exists();

            if ($exists) {
                return;
            }

            $fromDonorService->createFromDonor($product, SystemProduct::STATUS_PENDING);
        });
    }

    public function failed(Throwable $e): void
    {
        Log::error('EnsureSystemProductFromDonorJob failed', [
            'donor_product_id' => $this->donorProductId,
            'message' => $e->getMessage(),
            'exception' => $e::class,
        ]);
    }
}
