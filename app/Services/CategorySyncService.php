<?php

namespace App\Services;

use App\Models\Category;
use App\Services\SadovodParser\Parsers\MenuParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sync categories using MenuParser (same source as parser runMenuOnly).
 * Does NOT parse donor HTML directly — uses MenuParser which is the canonical category source.
 *
 * Fields synced: name, slug, parent_id, url, sort_order
 * Does NOT delete categories that have products.
 */
class CategorySyncService
{
    public function __construct(
        protected MenuParser $menuParser
    ) {}

    public function sync(): array
    {
        $created = 0;
        $updated = 0;

        $result = $this->menuParser->parse();
        $sourceCategories = $result['categories'] ?? [];
        if (empty($sourceCategories)) {
            Log::warning('CategorySync: No categories from MenuParser');
            return ['created' => 0, 'updated' => 0];
        }

        $items = $this->flattenTree($sourceCategories);
        $slugToId = [];
        $order = 0;

        foreach ($items as $item) {
            $slug = $item['slug'];
            $parentSlug = $item['parent_slug'] ?? null;
            $name = $item['name'];
            $url = $item['url'] ?? null;

            $parentId = null;
            if ($parentSlug && isset($slugToId[$parentSlug])) {
                $parentId = $slugToId[$parentSlug];
            }

            $existing = Category::where('external_slug', $slug)
                ->orWhere('slug', $slug)
                ->first();

            if ($existing) {
                $existing->update([
                    'name' => mb_substr($name, 0, 499),
                    'parent_id' => $parentId,
                    'external_slug' => $slug,
                    'url' => $url,
                ]);
                $updated++;
                $slugToId[$slug] = $existing->id;
            } else {
                $cat = Category::create([
                    'name' => mb_substr($name, 0, 499),
                    'slug' => $slug,
                    'external_slug' => $slug,
                    'url' => $url,
                    'parent_id' => $parentId,
                    'sort_order' => $order++,
                    'enabled' => true,
                    'linked_to_parser' => false,
                    'products_count' => 0,
                ]);
                $created++;
                $slugToId[$slug] = $cat->id;
            }
        }

        $this->rebuildProductsCount();

        return ['created' => $created, 'updated' => $updated];
    }

    /**
     * Flatten MenuParser tree to [name, slug, url, parent_slug].
     */
    protected function flattenTree(array $tree, ?string $parentSlug = null): array
    {
        $result = [];
        foreach ($tree as $node) {
            $slug = $node['slug'] ?? basename(rtrim($node['url'] ?? '', '/'));
            if (!$slug) continue;

            $baseUrl = rtrim(config('sadovod.base_url', 'https://sadovodbaza.ru'), '/');
            $path = $node['url'] ?? '';
            $url = str_starts_with($path, 'http') ? $path : $baseUrl . $path;

            $result[] = [
                'name' => $node['title'] ?? ucfirst(str_replace(['-', '_'], ' ', $slug)),
                'slug' => $slug,
                'url' => $url,
                'parent_slug' => $parentSlug,
            ];

            if (!empty($node['children'])) {
                $childItems = $this->flattenTree($node['children'], $slug);
                $result = array_merge($result, $childItems);
            }
        }
        return $result;
    }

    protected function rebuildProductsCount(): void
    {
        Category::query()->update(['products_count' => 0]);
        $counts = DB::table('products')
            ->whereNotNull('category_id')
            ->selectRaw('category_id, count(*) as c')
            ->groupBy('category_id')
            ->pluck('c', 'category_id');

        foreach ($counts as $catId => $c) {
            Category::where('id', $catId)->update(['products_count' => $c]);
        }
    }
}
