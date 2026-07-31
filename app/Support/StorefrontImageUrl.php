<?php

namespace App\Support;

/**
 * Normalize product/media URLs for the public storefront.
 * External donor images are served via same-origin proxy (hotlink / DNS safe).
 */
class StorefrontImageUrl
{
    /** @var list<string> */
    private const EXTERNAL_HOSTS = [
        'sadovodbaza.ru',
        'www.sadovodbaza.ru',
    ];

    public static function publicUrl(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }

        if (str_contains($url, '/api/v1/public/image')) {
            return $url;
        }

        if (self::isOwnAsset($url)) {
            return $url;
        }

        if (self::isAllowedExternal($url)) {
            return rtrim((string) config('app.url'), '/').'/api/v1/public/image?url='.rawurlencode($url);
        }

        return $url;
    }

    public static function isAllowedExternal(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return in_array($host, self::EXTERNAL_HOSTS, true);
    }

    public static function isOwnAsset(string $url): bool
    {
        if (str_starts_with($url, '/')) {
            return true;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }

        foreach ([
            'cheepy.shop',
            'www.cheepy.shop',
            'online-parser.cheepy.shop',
            'photos.cheepy.shop',
            'cdn.cheepy.shop',
        ] as $own) {
            if ($host === $own || str_ends_with($host, '.'.$own)) {
                return true;
            }
        }

        $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        if ($appHost !== '' && $host === $appHost) {
            return true;
        }

        return false;
    }
}
