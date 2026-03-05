<?php

namespace App\Services\SadovodParser\Parsers;

use App\Services\SadovodParser\HttpClient;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Extract categories from donor menu #menu-catalog.
 * Structure: .menu-item > a.top-category (parent) + .sub-menu-wrap a.sub-category (children).
 *
 * @return array{categories: array<int, array{name: string, slug: string, url: string, parent_slug: ?string}>}
 */
class MenuParser
{
    private HttpClient $http;
    private string $baseUrl;
    private array $excludeLinks;
    private array $excludeText;

    public function __construct(HttpClient $http, array $config = [])
    {
        $this->http = $http;
        $this->baseUrl = rtrim($config['base_url'] ?? config('sadovod.base_url', 'https://sadovodbaza.ru'), '/');
        $this->excludeLinks = $config['exclude_menu_links'] ?? ['/link/3'];
        $this->excludeText = $config['exclude_menu_text'] ?? ['Женская одежда ТГ'];
    }

    /**
     * Load donor homepage, find #menu-catalog, extract categories.
     *
     * @return array{categories: array<int, array{name: string, slug: string, url: string, parent_slug: ?string}>}
     */
    public function parse(string $html = null): array
    {
        $crawler = $html ? (new Crawler())->addHtmlContent($html, 'UTF-8') : $this->http->getCrawler('/');

        $categories = $this->extractFromMenuCatalog($crawler);

        return ['categories' => $categories];
    }

    /**
     * Extract categories from #menu-catalog.
     * Algorithm:
     * - iterate .menu-item
     * - extract top: .menu-item > a.top-category (name, slug, url)
     * - extract subs: .sub-menu-wrap a.sub-category (parent_slug = top slug)
     * - return flat list with parent_slug, no duplicates
     */
    private function extractFromMenuCatalog(Crawler $crawler): array
    {
        $flat = [];
        $seen = [];

        $menuCrawler = null;
        try {
            if ($crawler->filter('#menu-catalog')->count() > 0) {
                $menuCrawler = $crawler->filter('#menu-catalog');
            }
        } catch (\Throwable $e) {
        }

        if (!$menuCrawler || $menuCrawler->count() === 0) {
            return $this->extractFromMenuMainFallback($crawler);
        }

        try {
            $menuCrawler->filter('.menu-item')->each(function (Crawler $item) use (&$flat, &$seen) {
                // Top category: .menu-item > a.top-category or a.top-category
                $topLink = $item->filter('a.top-category[href*="/catalog/"]')->getNode(0);
                if (!$topLink) {
                    $topLink = $item->filter('.menu-item > a.top-category')->getNode(0);
                }
                if (!$topLink) {
                    $topLink = $item->filter('> a[href*="/catalog/"]')->getNode(0);
                }
                if (!$topLink) {
                    return;
                }

                $href = $topLink->getAttribute('href');
                if (!$href || str_contains($href, '/link/')) {
                    return;
                }
                $path = parse_url($href, PHP_URL_PATH) ?: $href;
                if (!str_contains($path, '/catalog/')) {
                    return;
                }

                $slug = basename(rtrim($path, '/'));
                $name = trim($topLink->textContent ?? '');
                if (in_array($name, $this->excludeText, true) || in_array($path, $this->excludeLinks, true)) {
                    return;
                }
                if (!$slug || !$name) {
                    return;
                }

                $url = str_starts_with($path, 'http') ? $path : $this->baseUrl . $path;
                $key = $path;
                if (empty($seen[$key])) {
                    $seen[$key] = true;
                    $flat[] = [
                        'name' => $name,
                        'slug' => $slug,
                        'url' => $url,
                        'parent_slug' => null,
                    ];
                }

                // Subcategories: .sub-menu-wrap a.sub-category
                $item->filter('.sub-menu-wrap a.sub-category')->each(function (Crawler $sub) use (&$flat, &$seen, $slug) {
                    $h = $sub->attr('href');
                    if (!$h || str_contains($h, '/link/')) {
                        return;
                    }
                    $p = parse_url($h, PHP_URL_PATH) ?: $h;
                    if (!str_contains($p, '/catalog/')) {
                        return;
                    }
                    $subSlug = basename(rtrim($p, '/'));
                    $subName = trim($sub->text());
                    if (!$subSlug || !$subName) {
                        return;
                    }
                    $subUrl = str_starts_with($p, 'http') ? $p : $this->baseUrl . $p;
                    $subKey = $p;
                    if (empty($seen[$subKey])) {
                        $seen[$subKey] = true;
                        $flat[] = [
                            'name' => $subName,
                            'slug' => $subSlug,
                            'url' => $subUrl,
                            'parent_slug' => $slug,
                        ];
                    }
                });
            });
        } catch (\Throwable $e) {
        }

        if (empty($flat)) {
            return $this->extractFromMenuMainFallback($crawler);
        }
        return array_values($flat);
    }

    /**
     * Fallback if #menu-catalog not found: use #menu-main with same selectors.
     */
    private function extractFromMenuMainFallback(Crawler $crawler): array
    {
        $flat = [];
        $seen = [];
        $menuCrawler = null;
        try {
            if ($crawler->filter('#menu-main')->count() > 0) {
                $menuCrawler = $crawler->filter('#menu-main');
            }
        } catch (\Throwable $e) {
            return [];
        }
        if (!$menuCrawler) {
            return [];
        }

        try {
            $menuCrawler->filter('.menu-item')->each(function (Crawler $item) use (&$flat, &$seen) {
                $topLink = $item->filter('a.top-category[href*="/catalog/"]')->getNode(0);
                if (!$topLink) {
                    return;
                }
                $href = $topLink->getAttribute('href');
                if (!$href || str_contains($href, '/link/')) {
                    return;
                }
                $path = parse_url($href, PHP_URL_PATH) ?: $href;
                $slug = basename(rtrim($path, '/'));
                $name = trim($topLink->textContent ?? '');
                if (in_array($name, $this->excludeText, true)) {
                    return;
                }
                $url = str_starts_with($path, 'http') ? $path : $this->baseUrl . $path;
                if (empty($seen[$path])) {
                    $seen[$path] = true;
                    $flat[] = ['name' => $name, 'slug' => $slug, 'url' => $url, 'parent_slug' => null];
                }
                $item->filter('.sub-menu-wrap a.sub-category, .sub-menu .sub-category')->each(function (Crawler $sub) use (&$flat, &$seen, $slug) {
                    $h = $sub->attr('href');
                    if (!$h || str_contains($h, '/link/')) {
                        return;
                    }
                    $p = parse_url($h, PHP_URL_PATH) ?: $h;
                    if (!str_contains($p, '/catalog/')) {
                        return;
                    }
                    $subSlug = basename(rtrim($p, '/'));
                    $subName = trim($sub->text());
                    $subUrl = str_starts_with($p, 'http') ? $p : $this->baseUrl . $p;
                    if (empty($seen[$p])) {
                        $seen[$p] = true;
                        $flat[] = ['name' => $subName, 'slug' => $subSlug, 'url' => $subUrl, 'parent_slug' => $slug];
                    }
                });
            });
        } catch (\Throwable $e) {
        }
        return array_values($flat);
    }
}
