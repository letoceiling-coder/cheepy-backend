<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExcludedRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExcludedController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ExcludedRule::with('category:id,name,slug');
        if ($scope = $request->input('scope')) {
            $query->where('scope', $scope);
        }
        if ($type = $request->input('type')) {
            $query->where('type', $type);
        }
        if ($request->input('active_only')) {
            $query->where('is_active', true)
                  ->where(fn($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
        }
        $rules = $query->orderBy('priority', 'desc')->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 50));

        return response()->json(['data' => $rules->items(), 'total' => $rules->total()]);
    }

    public function store(Request $request): JsonResponse
    {
        $rule = ExcludedRule::create([
            'pattern' => $request->input('pattern'),
            'type' => $request->input('type', 'word'),
            'action' => $request->input('action', 'hide'),
            'replacement' => $request->input('replacement'),
            'scope' => $request->input('scope', 'global'),
            'category_id' => $request->input('category_id'),
            'product_type' => $request->input('product_type'),
            'apply_to_fields' => $request->input('apply_to_fields'),
            'expires_at' => $request->input('expires_at'),
            'priority' => (int) $request->input('priority', 0),
            'comment' => $request->input('comment'),
            'is_active' => true,
        ]);
        return response()->json($rule, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $rule = ExcludedRule::findOrFail($id);
        $rule->update($request->only([
            'pattern', 'type', 'action', 'replacement', 'scope',
            'category_id', 'product_type', 'apply_to_fields',
            'expires_at', 'is_active', 'priority', 'comment',
        ]));
        return response()->json($rule->fresh());
    }

    public function destroy(int $id): JsonResponse
    {
        ExcludedRule::findOrFail($id)->delete();
        return response()->json(['message' => 'Удалено']);
    }

    /**
     * POST /api/v1/excluded/test
     * Тест правила против текста
     */
    public function test(Request $request): JsonResponse
    {
        $text = $request->input('text', '');
        $field = $request->input('field', 'title');
        $categoryId = $request->input('category_id');

        $result = ExcludedRule::applyRules($text, $field, $categoryId);

        return response()->json([
            'original' => $text,
            'result' => $result['text'],
            'flagged' => $result['flagged'],
            'hide' => $result['hide'],
            'delete' => $result['delete'],
        ]);
    }
}
