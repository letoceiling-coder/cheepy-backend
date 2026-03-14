<?php

namespace App\Services\Parser;

use Symfony\Component\DomCrawler\Crawler;

class AttributeExtractor
{
    /**
     * Extract normalized product attributes from page crawler.
     *
     * @return array<string, mixed>
     */
    public function extract(Crawler $crawler): array
    {
        $text = '';
        try {
            $text = $crawler->filter('main')->count() > 0
                ? $crawler->filter('main')->text()
                : $crawler->filter('body')->text();
        } catch (\Throwable) {
            $text = '';
        }

        $pairs = $this->extractKeyValuePairs($text);

        return [
            'title' => $this->extractTitle($crawler),
            'brand' => $pairs['бренд'] ?? null,
            'price' => $this->extractPrice($text),
            'sizes' => $this->extractSizes($pairs, $text),
            'color' => $pairs['цвет'] ?? null,
            'description' => $this->extractDescription($crawler),
            'characteristics' => $pairs,
        ];
    }

    private function extractTitle(Crawler $crawler): string
    {
        try {
            return trim($crawler->filter('h1')->first()->text());
        } catch (\Throwable) {
            return '';
        }
    }

    private function extractDescription(Crawler $crawler): string
    {
        try {
            $parts = [];
            $crawler->filter('main p')->each(function (Crawler $node) use (&$parts): void {
                $t = trim($node->text());
                if ($t !== '') {
                    $parts[] = $t;
                }
            });
            return trim(implode("\n", array_slice($parts, 0, 6)));
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @return array<string, string>
     */
    private function extractKeyValuePairs(string $text): array
    {
        $result = [];
        foreach (preg_split('/\R/u', $text) as $line) {
            $line = trim((string) $line);
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$k, $v] = array_map('trim', explode(':', $line, 2));
            if ($k !== '' && $v !== '') {
                $result[mb_strtolower($k)] = preg_replace('/\s+/u', ' ', $v) ?? $v;
            }
        }
        return $result;
    }

    private function extractPrice(string $text): ?string
    {
        if (preg_match('/([\d\s]{2,})\s*₽/u', $text, $m)) {
            return trim(($m[1] ?? '') . ' ₽');
        }
        return null;
    }

    /**
     * @param array<string, string> $pairs
     * @return array<int, string>
     */
    private function extractSizes(array $pairs, string $text): array
    {
        $source = $pairs['размер'] ?? $pairs['размеры'] ?? '';
        if ($source === '' && preg_match('/Размер(?:ы|ный ряд)?\s*:\s*([^\n\r]+)/ui', $text, $m)) {
            $source = trim((string) ($m[1] ?? ''));
        }
        if ($source === '') {
            return [];
        }

        $parts = preg_split('/[,\s\/;|]+/u', $source) ?: [];
        $sizes = array_values(array_unique(array_filter(array_map(
            static fn (string $v): string => mb_strtoupper(trim($v)),
            $parts
        ))));

        return array_slice($sizes, 0, 20);
    }
}
