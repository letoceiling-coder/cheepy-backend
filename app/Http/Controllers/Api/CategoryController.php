<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    /**
     * GET /api/v1/categories
     */
    public function index(Request $request): JsonResponse
    {
        $tree = $request->boolean('tree', false);
        $query = Category::query();

        if ($search = $request->input('search')) {
            $query->where('name', 'like', "%{$search}%");
        }
        if ($request->input('enabled_only')) {
            $query->where('enabled', true);
        }

        if ($tree) {
            // Полное дерево произвольной глубины (фильтры, парсер, админка).
            $categories = $query->whereNull('parent_id')
                ->orderBy('sort_order')
                ->with(self::categoryNestedChildrenWith(40))
                ->get();

            return response()->json(['data' => $categories->map(fn($c) => $this->formatCategoryTree($c))]);
        }

        $perPage = min(max((int) $request->input('per_page', 100), 1), 2000);
        $categories = $query->orderBy('sort_order')->paginate($perPage);

        return response()->json([
            'data' => $categories->map(fn($c) => $this->formatCategory($c))->values(),
            'total' => $categories->total(),
            'meta' => [
                'total' => $categories->total(),
                'per_page' => $categories->perPage(),
                'current_page' => $categories->currentPage(),
                'last_page' => $categories->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/v1/categories/{id}
     */
    public function show(int $id): JsonResponse
    {
        $category = Category::with(['parent', 'children'])->findOrFail($id);
        return response()->json($this->formatCategoryFull($category));
    }

    /**
     * PATCH /api/v1/categories/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $category = Category::findOrFail($id);
        $category->update($request->only([
            'enabled', 'linked_to_parser', 'sort_order',
            'parser_products_limit', 'parser_max_pages', 'parser_depth_limit',
            'name', 'icon',
        ]));
        return response()->json($this->formatCategoryFull($category->fresh(['parent', 'children'])));
    }

    /**
     * POST /api/v1/categories/reorder
     * Сохранение нового порядка (drag&drop)
     */
    public function reorder(Request $request): JsonResponse
    {
        $items = $request->input('items', []);
        foreach ($items as $item) {
            Category::where('id', $item['id'])->update([
                'sort_order' => $item['sort_order'],
                'parent_id' => $item['parent_id'] ?? null,
            ]);
        }
        return response()->json(['message' => 'Порядок сохранён']);
    }

    /**
     * GET /api/v1/categories/{id}/filters
     * Доступные значения фильтров для категории (из product_attributes)
     */
    public function availableFilters(int $id): JsonResponse
    {
        $category = Category::findOrFail($id);

        $attrs = \App\Models\ProductAttribute::where('category_id', $id)
            ->select('attr_name', 'attr_value', 'attr_type')
            ->distinct()
            ->get()
            ->groupBy('attr_name')
            ->map(fn($group, $name) => [
                'attr_name' => $name,
                'attr_type' => $group->first()->attr_type,
                'values' => $group->pluck('attr_value')->unique()->values(),
                'count' => $group->count(),
            ])
            ->values();

        return response()->json([
            'category_id' => $id,
            'category_name' => $category->name,
            'attributes' => $attrs,
        ]);
    }

    /**
     * Вложенная eager-load связь children до заданной глубины.
     *
     * @return array<string, mixed>
     */
    private static function categoryNestedChildrenWith(int $depth): array
    {
        if ($depth <= 0) {
            return [];
        }

        return [
            'children' => fn ($q) => $q->orderBy('sort_order')->with(self::categoryNestedChildrenWith($depth - 1)),
        ];
    }

    private function formatCategory(Category $c): array
    {
        return [
            'id' => $c->id,
            'external_slug' => $c->external_slug,
            'name' => $c->name,
            'slug' => $c->slug,
            'parent_id' => $c->parent_id,
            'sort_order' => $c->sort_order,
            'icon' => $c->icon,
            'enabled' => $c->enabled,
            'linked_to_parser' => $c->linked_to_parser,
            'parser_products_limit' => $c->parser_products_limit,
            'parser_max_pages' => $c->parser_max_pages,
            'parser_depth_limit' => $c->parser_depth_limit,
            'products_count' => $c->products_count,
            'last_parsed_at' => $c->last_parsed_at?->toIso8601String(),
        ];
    }

    private function formatCategoryFull(Category $c): array
    {
        $data = $this->formatCategory($c);
        $data['parser_settings'] = [
            'products_limit' => $c->parser_products_limit,
            'max_pages' => $c->parser_max_pages,
            'depth_limit' => $c->parser_depth_limit,
        ];
        $data['parent'] = $c->parent ? $this->formatCategory($c->parent) : null;
        $data['children'] = $c->children?->map(fn($ch) => $this->formatCategory($ch))->toArray() ?? [];
        return $data;
    }

    private function formatCategoryTree(Category $c): array
    {
        $data = $this->formatCategory($c);
        $data['children'] = $c->children->map(fn($ch) => $this->formatCategoryTree($ch))->toArray();
        return $data;
    }
}
