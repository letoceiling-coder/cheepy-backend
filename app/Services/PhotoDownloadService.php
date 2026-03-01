<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductPhoto;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Storage;

class PhotoDownloadService
{
    private Client $client;

    public function __construct()
    {
        $this->client = new Client([
            'timeout' => 30,
            'verify' => false,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0 Safari/537.36',
                'Referer' => 'https://sadovodbaza.ru/',
            ],
        ]);
    }

    /**
     * Скачать все фото для продукта
     */
    public function downloadProductPhotos(Product $product, bool $force = false): array
    {
        $photos = $product->photos ?? [];
        if (empty($photos)) return ['downloaded' => 0, 'failed' => 0, 'skipped' => 0];

        $downloaded = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($photos as $index => $photoUrl) {
            $normalizedUrl = $this->normalizeUrl($photoUrl);
            $existing = ProductPhoto::where('product_id', $product->id)
                ->where('original_url', $normalizedUrl)
                ->first();

            if ($existing && !$force && $existing->download_status === 'done') {
                $skipped++;
                continue;
            }

            $photoRecord = $existing ?? ProductPhoto::create([
                'product_id' => $product->id,
                'original_url' => $normalizedUrl,
                'medium_url' => $this->getMediumUrl($normalizedUrl),
                'sort_order' => $index,
                'is_primary' => $index === 0,
                'download_status' => 'pending',
            ]);

            $result = $this->downloadOne($normalizedUrl, $product->external_id, $index);

            if ($result['success']) {
                $photoRecord->update([
                    'local_path' => $result['local_path'],
                    'local_medium_path' => $result['local_medium_path'] ?? null,
                    'hash' => $result['hash'],
                    'mime_type' => $result['mime_type'],
                    'file_size' => $result['file_size'],
                    'download_status' => 'done',
                ]);
                $downloaded++;
            } else {
                $photoRecord->update(['download_status' => 'failed']);
                $failed++;
            }
        }

        if ($downloaded > 0 || $skipped > 0) {
            $product->update(['photos_downloaded' => true]);
        }

        return ['downloaded' => $downloaded, 'failed' => $failed, 'skipped' => $skipped];
    }

    /**
     * Скачать пакет фото для нескольких продуктов
     */
    public function downloadBatch(iterable $products, callable $onProgress = null): array
    {
        $total = ['downloaded' => 0, 'failed' => 0, 'skipped' => 0, 'products' => 0];
        foreach ($products as $product) {
            $result = $this->downloadProductPhotos($product);
            $total['downloaded'] += $result['downloaded'];
            $total['failed'] += $result['failed'];
            $total['skipped'] += $result['skipped'];
            $total['products']++;
            if ($onProgress) {
                $onProgress($total, $product);
            }
        }
        return $total;
    }

    private function downloadOne(string $url, string $productId, int $index): array
    {
        try {
            $response = $this->client->get($url);
            $body = (string) $response->getBody();
            $mimeType = $response->getHeaderLine('Content-Type');
            $mimeType = explode(';', $mimeType)[0];

            $ext = $this->getExtFromMime($mimeType) ?? pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION) ?? 'jpg';
            $hash = md5($body);

            // Структура: photos/{product_id}/{index}_{hash}.jpg
            $localPath = "photos/{$productId}/{$index}_{$hash}.{$ext}";
            Storage::disk('local')->put($localPath, $body);

            // Попробуем скачать medium-версию
            $mediumUrl = $this->getMediumUrl($url);
            $localMediumPath = null;
            if ($mediumUrl !== $url) {
                try {
                    $medResponse = $this->client->get($mediumUrl);
                    $medBody = (string) $medResponse->getBody();
                    $localMediumPath = "photos/{$productId}/{$index}_{$hash}_medium.{$ext}";
                    Storage::disk('local')->put($localMediumPath, $medBody);
                } catch (\Throwable $e) {
                    // medium не критично
                }
            }

            return [
                'success' => true,
                'local_path' => $localPath,
                'local_medium_path' => $localMediumPath,
                'hash' => $hash,
                'mime_type' => $mimeType,
                'file_size' => strlen($body),
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Превратить _img_big.jpg в _img_medium.jpg
     */
    private function getMediumUrl(string $url): string
    {
        return str_replace('_img_big.', '_img_medium.', $url);
    }

    /**
     * Нормализовать URL: добавить домен если относительный
     */
    private function normalizeUrl(string $url): string
    {
        if (str_starts_with($url, 'http')) {
            return $url;
        }
        $base = rtrim(config('sadovod.base_url', 'https://sadovodbaza.ru'), '/');
        return $base . '/' . ltrim($url, '/');
    }

    private function getExtFromMime(string $mime): ?string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
            default => null,
        };
    }
}
