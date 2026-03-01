<?php

namespace App\Services\SadovodParser\Parsers;

use App\Services\SadovodParser\HttpClient;
use Symfony\Component\DomCrawler\Crawler;

class MenuParser
{
    private HttpClient $http;
    private array $excludeLinks;
    private array $excludeText;

    public function __construct(HttpClient $http, array $config = [])
    {
        $this->http = $http;
        $this->excludeLinks = $config['exclude_menu_links'] ?? ['/link/3'];
        $this->excludeText = $config['exclude_menu_text'] ?? ['Женская одежда ТГ'];
    }

    /**
     * Parse main page: extract block #menu-main and build category tree (excluding TG link).
     *
     * @return array{categories: array, menu_main_html: string|null}
     */
    public function parse(string $html = null): array
    {
        $crawler = $html ? (new Crawler())->addHtmlContent($html, 'UTF-8') : $this->http->getCrawler('/');

        $menuMainHtml = null;
        $menuCrawler = null;
        try {
            if ($crawler->filter('#menu-main')->count() > 0) {
                $menuMainHtml = $crawler->filter('#menu-main')->html();
                $menuCrawler = $crawler->filter('#menu-main');
            }
        } catch (\Throwable $e) {
        }

        $categories = $this->extractCategoriesFromPage($crawler, $menuCrawler);

        return [
            'menu_main_html' => $menuMainHtml,
            'categories' => $categories,
        ];
    }

    /**
     * Extract all catalog categories. If #menu-main exists, build tree (parent + children).
     * Exclude "Женская одежда ТГ" and /link/3.
     * @param Crawler|null $menuCrawler Crawler of #menu-main
     */
    private function extractCategoriesFromPage(Crawler $crawler, ?Crawler $menuCrawler = null): array
    {
        $seen = [];
        $flat = [];

        if ($menuCrawler && $menuCrawler->count() > 0) {
            $menuCrawler->filter('a[href*="/catalog/"]')->each(function (Crawler $node) use (&$flat, &$seen) {
                $this->addCategoryFromLink($node, $flat, $seen);
            });
        }

        $crawler->filter('a[href*="/catalog/"]')->each(function (Crawler $node) use (&$flat, &$seen) {
            $this->addCategoryFromLink($node, $flat, $seen);
        });

        $flat = array_values(array_filter($flat, function ($cat) {
            $href = $cat['url'] ?? '';
            $title = $cat['title'] ?? '';
            if (in_array($href, $this->excludeLinks, true)) {
                return false;
            }
            if (in_array($title, $this->excludeText, true)) {
                return false;
            }
            if (str_contains($href, '/link/')) {
                return false;
            }
            return true;
        }));

        if ($menuCrawler && $menuCrawler->count() > 0) {
            $tree = $this->buildTreeFromMenuMain($menuCrawler);
            if (!empty($tree)) {
                return $this->mergeTreeWithFlat($tree, $flat);
            }
        }

        usort($flat, fn ($a, $b) => strcmp($a['title'] ?? '', $b['title'] ?? ''));
        return $this->buildCategoryTree($flat);
    }

    /**
     * Build category tree from #menu-main: .menu-item > .top-category (parent) + .sub-menu .sub-category (children).
     */
    private function buildTreeFromMenuMain(Crawler $menuCrawler): array
    {
        $tree = [];
        try {
            $menuCrawler->filter('.menu-item')->each(function (Crawler $item) use (&$tree) {
                $parentLink = $item->filter('.top-category[href*="/catalog/"]')->getNode(0);
                if (!$parentLink) {
                    return;
                }
                $href = $parentLink->getAttribute('href');
                if (!$href || str_contains($href, '/link/')) {
                    return;
                }
                $path = parse_url($href, PHP_URL_PATH) ?: $href;
                $slug = basename(rtrim($path, '/'));
                $title = trim($parentLink->textContent ?? '');
                if (in_array($title, $this->excludeText, true)) {
                    return;
                }
                $children = [];
                $item->filter('.sub-menu .sub-category, .sub-menu-wrap .sub-category')->each(function (Crawler $sub) use (&$children) {
                    $h = $sub->attr('href');
                    if (!$h || str_contains($h, '/link/')) {
                        return;
                    }
                    $p = parse_url($h, PHP_URL_PATH) ?: $h;
                    if (!str_contains($p, '/catalog/')) {
                        return;
                    }
                    $children[] = [
                        'title' => trim($sub->text()),
                        'url' => $p,
                        'slug' => basename(rtrim($p, '/')),
                    ];
                });
                $tree[] = [
                    'title' => $title,
                    'url' => $path,
                    'slug' => $slug,
                    'children' => $children,
                ];
            });
        } catch (\Throwable $e) {
        }
        return $tree;
    }

    private function mergeTreeWithFlat(array $tree, array $flat): array
    {
        $inTree = [];
        foreach ($tree as $node) {
            $inTree[$node['slug']] = true;
            foreach ($node['children'] ?? [] as $ch) {
                $inTree[$ch['slug'] ?? ''] = true;
            }
        }
        foreach ($flat as $c) {
            $slug = $c['slug'] ?? '';
            if ($slug && empty($inTree[$slug])) {
                $tree[] = ['title' => $c['title'], 'url' => $c['url'], 'slug' => $slug, 'children' => []];
            }
        }
        usort($tree, fn ($a, $b) => strcmp($a['title'] ?? '', $b['title'] ?? ''));
        return $tree;
    }

    private function addCategoryFromLink(Crawler $node, array &$categories, array &$seen): void
    {
        $href = $node->attr('href');
        if (!$href || str_contains($href, '/link/')) {
            return;
        }
        $path = parse_url($href, PHP_URL_PATH) ?: $href;
        if (!str_contains($path, '/catalog/')) {
            return;
        }
        $key = $path;
        if (isset($seen[$key])) {
            return;
        }
        $title = trim($node->text());
        if ($title === '') {
            return;
        }
        $seen[$key] = true;
        $categories[] = [
            'title' => $title,
            'url' => $path,
            'slug' => basename(rtrim($path, '/')),
        ];
    }

    /**
     * Build a simple tree: top-level categories only (no nested subcategories on main page).
     * Subcategories are discovered on catalog pages.
     */
    private function buildCategoryTree(array $flat): array
    {
        $bySlug = [];
        foreach ($flat as $cat) {
            $slug = $cat['slug'] ?? basename($cat['url']);
            $bySlug[$slug] = [
                'title' => $cat['title'],
                'url' => $cat['url'],
                'slug' => $slug,
                'children' => [],
            ];
        }
        return array_values($bySlug);
    }
}
