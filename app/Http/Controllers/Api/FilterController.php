<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FilterConfig;
use App\Models\ProductAttribute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FilterController extends Controller
{
    /**
     * GET /api/v1/filters?category_id=
     */
    public function index(Request $request): JsonResponse
    {
        $query = FilterConfig::with('category:id,name,slug');
        if ($categoryId = $request->input('category_id')) {
            $query->where('category_id', $categoryId);
        }
        if ($request->input('active_only')) {
            $query->where('is_active', true);
        }
        $filters = $query->orderBy('sort_order')->get();
        return response()->json(['data' => $filters]);
    }

    public function store(Request $request): JsonResponse
    {
        $filter = FilterConfig::updateOrCreate(
            [
                'category_id' => $request->input('category_id'),
                'attr_name' => $request->input('attr_name'),
            ],
            [
                'display_name' => $request->input('display_name', $request->input('attr_name')),
                'display_type' => $request->input('display_type', 'checkbox'),
                'sort_order' => (int) $request->input('sort_order', 0),
                'range_min' => $request->input('range_min'),
                'range_max' => $request->input('range_max'),
                'preset_values' => $request->input('preset_values'),
                'is_active' => $request->boolean('is_active', true),
                'is_filterable' => $request->boolean('is_filterable', true),
            ]
        );
        return response()->json($filter, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $filter = FilterConfig::findOrFail($id);
        $filter->update($request->only([
            'display_name', 'display_type', 'sort_order',
            'range_min', 'range_max', 'preset_values',
            'is_active', 'is_filterable',
        ]));
        return response()->json($filter->fresh());
    }

    public function destroy(int $id): JsonResponse
    {
        FilterConfig::findOrFail($id)->delete();
        return response()->json(['message' => 'Удалено']);
    }

    /**
     * GET /api/v1/filters/{categoryId}/values
     * Фактические значения для фильтров из БД
     */
    public function values(int $categoryId): JsonResponse
    {
        $activeFilters = FilterConfig::where('category_id', $categoryId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $result = [];
        foreach ($activeFilters as $filter) {
            $values = ProductAttribute::where('category_id', $categoryId)
                ->where('attr_name', $filter->attr_name)
                ->distinct()
                ->pluck('attr_value')
                ->sort()
                ->values();

            $entry = [
                'attr_name' => $filter->attr_name,
                'display_name' => $filter->display_name,
                'display_type' => $filter->display_type,
                'sort_order' => $filter->sort_order,
                'is_filterable' => $filter->is_filterable,
            ];

            if ($filter->display_type === 'range') {
                $entry['min'] = $filter->range_min ?? (float) $values->min();
                $entry['max'] = $filter->range_max ?? (float) $values->max();
            } else {
                $entry['values'] = $filter->preset_values ?? $values->toArray();
            }

            $result[] = $entry;
        }

        return response()->json(['category_id' => $categoryId, 'filters' => $result]);
    }
}
