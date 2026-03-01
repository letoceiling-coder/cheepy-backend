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
            // Только корневые, с дочерними
            $categories = $query->whereNull('parent_id')
                ->orderBy('sort_order')
                ->with(['children' => fn($q) => $q->orderBy('sort_order')
                    ->with(['children' => fn($q2) => $q2->orderBy('sort_order')])])
                ->get();
            return response()->json(['data' => $categories->map(fn($c) => $this->formatCategoryTree($c))]);
        }

        $categories = $query->orderBy('sort_order')->paginate($request->input('per_page', 100));
        return response()->json([
            'data' => $categories->map(fn($c) => $this->formatCategory($c)),
            'total' => $categories->total(),
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
