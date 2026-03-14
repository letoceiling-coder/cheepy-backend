<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductPhoto;
use App\Models\ParserSetting;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PhotoDownloadService
{
    private Client $client;
    private int $delayMinMs = 1500;
    private int $delayMaxMs = 3000;

    public function __construct()
    {
        $settings = ParserSetting::current();
        $this->delayMinMs = max(100, (int) ($settings->request_delay_min ?? 1500));
        $this->delayMaxMs = max($this->delayMinMs, (int) ($settings->request_delay_max ?? 3000));
        $this->client = new Client([
            'timeout' => max(10, (int) ($settings->timeout_seconds ?? 60)),
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
        $attempt = 0;
        $maxAttempts = 3;
        while ($attempt < $maxAttempts) {
            $attempt++;
            try {
                usleep(random_int($this->delayMinMs * 1000, $this->delayMaxMs * 1000));
                $response = $this->client->get($url, [
                    'headers' => [
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0 Safari/537.36',
                        'Accept' => 'image/*',
                        'Accept-Language' => 'ru-RU,ru;q=0.9',
                        'Referer' => 'https://sadovodbaza.ru/',
                    ],
                ]);
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
                $msg = $e->getMessage();
                if (str_contains($msg, 'timed out') || str_contains($msg, 'Connection timed out') || str_contains($msg, 'cURL error 28')) {
                    Log::warning('Parser timeout', ['url' => $url, 'attempt' => $attempt]);
                }
                if ($attempt < $maxAttempts) {
                    usleep($attempt * 2_000_000);
                    continue;
                }
                return ['success' => false, 'error' => $msg];
            }
        }
        return ['success' => false, 'error' => 'Photo download failed'];
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
