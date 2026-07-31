<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\StorefrontImageUrl;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class PublicImageController extends Controller
{
    public function show(Request $request): Response
    {
        $url = trim((string) $request->query('url', ''));
        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            abort(400, 'Invalid url');
        }

        if (! StorefrontImageUrl::isAllowedExternal($url)) {
            abort(403, 'Host not allowed');
        }

        $cacheKey = 'storefront_image:'.sha1($url);
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['body'], $cached['mime'])) {
            return response($cached['body'], 200, $this->responseHeaders($cached['mime']));
        }

        $client = new Client([
            'timeout' => 25,
            'verify' => false,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept' => 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
                'Accept-Language' => 'ru-RU,ru;q=0.9',
                'Referer' => 'https://sadovodbaza.ru/',
            ],
            'curl' => self::curlResolveOptions($url),
        ]);

        try {
            $response = $client->get($url);
        } catch (GuzzleException) {
            $fallback = str_replace('_img_big.', '_img_medium.', $url);
            if ($fallback !== $url) {
                try {
                    $client = new Client([
                        'timeout' => 25,
                        'verify' => false,
                        'headers' => [
                            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                            'Accept' => 'image/*,*/*;q=0.8',
                            'Referer' => 'https://sadovodbaza.ru/',
                        ],
                        'curl' => self::curlResolveOptions($fallback),
                    ]);
                    $response = $client->get($fallback);
                    $url = $fallback;
                } catch (GuzzleException) {
                    abort(502, 'Upstream image fetch failed');
                }
            } else {
                abort(502, 'Upstream image fetch failed');
            }
        }

        $body = (string) $response->getBody();
        $mime = explode(';', $response->getHeaderLine('Content-Type'))[0] ?: 'image/jpeg';
        if ($body === '' || ! str_starts_with($mime, 'image/')) {
            abort(404, 'Not an image');
        }

        Cache::put($cacheKey, ['body' => $body, 'mime' => $mime], now()->addHours(24));

        return response($body, 200, $this->responseHeaders($mime));
    }

    /** @return array<int, mixed> */
    private static function curlResolveOptions(string $url): array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $resolve = match ($host) {
            'sadovodbaza.ru', 'www.sadovodbaza.ru' => [
                'sadovodbaza.ru:443:95.213.188.163',
                'www.sadovodbaza.ru:443:95.213.188.163',
            ],
            default => [],
        };

        return $resolve !== [] ? [CURLOPT_RESOLVE => $resolve] : [];
    }

    /** @return array<string, string> */
    private function responseHeaders(string $mime): array
    {
        return [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=86400, immutable',
            'Access-Control-Allow-Origin' => '*',
        ];
    }
}
