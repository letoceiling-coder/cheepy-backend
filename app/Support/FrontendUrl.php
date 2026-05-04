<?php

namespace App\Support;

/**
 * Надёжный базовый origin витрины для Success/Fail URL и т.п.
 * Защита от типичной ошибки .env: склейка двух баз (site.storehttp://dev.loc) или несколько значений через запятую.
 */
final class FrontendUrl
{
    /**
     * Одна строка вида https://example.com без завершающего слэша.
     *
     * @throws \RuntimeException
     */
    public static function base(?string $raw = null): string
    {
        $raw ??= (string) config('app.frontend_url', '');
        $normalized = self::normalize(trim($raw));
        if ($normalized === '') {
            throw new \RuntimeException('FRONTEND_URL is empty or invalid (expected a single https:// URL)');
        }

        return $normalized;
    }

    /** Публичный парсинг без исключений (диагностика, логи). */
    public static function tryBase(?string $raw = null): ?string
    {
        try {
            return self::base($raw ?? (string) config('app.frontend_url', ''));
        } catch (\RuntimeException) {
            return null;
        }
    }

    private static function normalize(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        // https://prodhttp://dev → вставить разделитель перед второй схемой
        $raw = preg_replace('#(\.[a-z]{2,})(https?)(://)#i', '$1,$2$3', $raw, 1) ?? $raw;

        foreach (preg_split('#\s*[;,|]+\s*#', $raw) ?: [] as $chunk) {
            $chunk = trim((string) $chunk);
            if ($chunk === '') {
                continue;
            }
            $origin = self::originFromChunk($chunk);
            if ($origin !== '') {
                return rtrim($origin, '/');
            }
        }

        $origin = self::originFromChunk($raw);

        return $origin !== '' ? rtrim($origin, '/') : '';
    }

    private static function originFromChunk(string $chunk): string
    {
        $chunk = trim($chunk);
        // http//host → http://host
        $chunk = preg_replace('#^(https?)/(?!/)#i', '$1://', $chunk) ?? $chunk;

        if (preg_match('#^https?://#i', $chunk)) {
            return self::parseHttpOrigin($chunk);
        }

        if (preg_match('#^[a-z0-9]([a-z0-9.-]*[a-z0-9])?\.[a-z]{2,}#i', $chunk)) {
            $hostOnly = preg_replace('#/.*$#', '', $chunk) ?? $chunk;

            return self::parseHttpOrigin('https://'.$hostOnly);
        }

        return '';
    }

    private static function parseHttpOrigin(string $url): string
    {
        $p = parse_url($url);
        if (! is_array($p) || empty($p['host'])) {
            return '';
        }
        $scheme = strtolower((string) ($p['scheme'] ?? 'https'));

        return $scheme.'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '');
    }
}
