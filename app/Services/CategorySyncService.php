<?php

namespace App\Services;

use App\Models\Category;
use App\Services\SadovodParser\Parsers\MenuParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sync categories using MenuParser (extracts from donor #menu-catalog).
 * Fields synced: name, slug, parent_id, url (source_url), sort_order.
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
        $items = $result['categories'] ?? [];
        if (empty($items)) {
            Log::warning('CategorySync: No categories from MenuParser');
            return ['created' => 0, 'updated' => 0];
        }

        // Remove duplicates by slug (keep first)
        $items = $this->deduplicateBySlug($items);

        // Sort: parents first (parent_slug null), then children (by parent_slug)
        $items = $this->sortForParentOrder($items);

        $slugToId = [];
        $order = 0;

        foreach ($items as $item) {
            $slug = $item['slug'] ?? null;
            $parentSlug = $item['parent_slug'] ?? null;
            $name = $item['name'] ?? '';
            $url = $item['url'] ?? null;

            if (!$slug) {
                continue;
            }

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
                    'sort_order' => $order++,
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
     * Remove duplicates by slug, keeping first occurrence.
     */
    protected function deduplicateBySlug(array $items): array
    {
        $seen = [];
        $result = [];
        foreach ($items as $item) {
            $slug = $item['slug'] ?? null;
            if (!$slug || isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;
            $result[] = $item;
        }
        return $result;
    }

    /**
     * Sort so parents (parent_slug null) come before their children.
     */
    protected function sortForParentOrder(array $items): array
    {
        $bySlug = [];
        foreach ($items as $item) {
            $slug = $item['slug'] ?? '';
            if ($slug) {
                $bySlug[$slug] = $item;
            }
        }

        $sorted = [];
        $seen = [];

        $addWithChildren = function (?string $parentSlug) use (&$addWithChildren, &$sorted, &$seen, $bySlug) {
            foreach ($bySlug as $slug => $item) {
                $itemParent = $item['parent_slug'] ?? null;
                if ($itemParent !== $parentSlug) {
                    continue;
                }
                if (isset($seen[$slug])) {
                    continue;
                }
                $seen[$slug] = true;
                $sorted[] = $item;
                $addWithChildren($slug);
            }
        };

        $addWithChildren(null);
        return $sorted;
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
