<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttributeDictionary;
use App\Models\AttributeRule;
use App\Models\AttributeSynonym;
use App\Models\AttributeValueNormalization;
use App\Services\AttributeExtractionService;
use App\Services\AttributeFacetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttributeRuleController extends Controller
{
    // ─────────────────────────────────────────────────────────────────
    // RULES
    // ─────────────────────────────────────────────────────────────────

    /** GET /api/v1/attribute-rules */
    public function index(Request $request): JsonResponse
    {
        $query = AttributeRule::query();

        if ($key = $request->input('attribute_key')) {
            $query->where('attribute_key', $key);
        }
        if ($request->input('enabled') !== null) {
            $query->where('enabled', $request->boolean('enabled'));
        }

        $rules = $query->orderBy('attribute_key')->orderBy('priority')->get();

        return response()->json(['data' => $rules]);
    }

    /** POST /api/v1/attribute-rules */
    public function store(Request $request, AttributeExtractionService $service): JsonResponse
    {
        $data = $request->validate([
            'attribute_key' => 'required|string|max:60',
            'display_name'  => 'required|string|max:120',
            'rule_type'     => 'required|in:regex,keyword',
            'pattern'       => 'required|string|max:500',
            'attr_type'     => 'nullable|in:text,size,color,number',
            'priority'      => 'nullable|integer|min:1|max:1000',
            'apply_synonyms'=> 'nullable|boolean',
            'enabled'       => 'nullable|boolean',
        ]);

        if ($data['rule_type'] === 'regex') {
            if (@preg_match('/' . $data['pattern'] . '/iu', '') === false) {
                return response()->json(['error' => 'Невалидный regex-паттерн'], 422);
            }
        }

        $rule = AttributeRule::create([
            'attribute_key'  => $data['attribute_key'],
            'display_name'   => $data['display_name'],
            'rule_type'      => $data['rule_type'],
            'pattern'        => $data['pattern'],
            'attr_type'      => $data['attr_type'] ?? 'text',
            'priority'       => $data['priority'] ?? 100,
            'apply_synonyms' => $data['apply_synonyms'] ?? true,
            'enabled'        => $data['enabled'] ?? true,
        ]);

        $service->clearCache();

        return response()->json($rule, 201);
    }

    /** PATCH /api/v1/attribute-rules/{id} */
    public function update(Request $request, int $id, AttributeExtractionService $service): JsonResponse
    {
        $rule = AttributeRule::findOrFail($id);

        $data = $request->validate([
            'attribute_key' => 'sometimes|string|max:60',
            'display_name'  => 'sometimes|string|max:120',
            'rule_type'     => 'sometimes|in:regex,keyword',
            'pattern'       => 'sometimes|string|max:500',
            'attr_type'     => 'sometimes|in:text,size,color,number',
            'priority'      => 'sometimes|integer|min:1|max:1000',
            'apply_synonyms'=> 'sometimes|boolean',
            'enabled'       => 'sometimes|boolean',
        ]);

        if (isset($data['pattern']) && ($data['rule_type'] ?? $rule->rule_type) === 'regex') {
            if (@preg_match('/' . $data['pattern'] . '/iu', '') === false) {
                return response()->json(['error' => 'Невалидный regex-паттерн'], 422);
            }
        }

        $rule->update($data);
        $service->clearCache();

        return response()->json($rule->fresh());
    }

    /** DELETE /api/v1/attribute-rules/{id} */
    public function destroy(int $id, AttributeExtractionService $service): JsonResponse
    {
        AttributeRule::findOrFail($id)->delete();
        $service->clearCache();
        return response()->json(['message' => 'Удалено']);
    }

    // ─────────────────────────────────────────────────────────────────
    // TEST
    // ─────────────────────────────────────────────────────────────────

    /**
     * POST /api/v1/attribute-rules/test
     * Run extraction on provided text and return extracted attributes.
     */
    public function test(Request $request, AttributeExtractionService $service): JsonResponse
    {
        $request->validate(['text' => 'required|string|max:5000']);
        $result = $service->extractFromText($request->input('text'));
        return response()->json(['extracted' => $result, 'data' => $result]);
    }

    /**
     * POST /api/v1/attribute-rules/rebuild
     * Trigger full rebuild for all products (runs synchronously — use for small DBs).
     */
    public function rebuild(AttributeExtractionService $service): JsonResponse
    {
        $result = $service->rebuildAll();
        return response()->json([
            'message'   => 'Атрибуты пересобраны',
            'processed' => $result['processed'],
            'saved'     => $result['saved'],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // SYNONYMS
    // ─────────────────────────────────────────────────────────────────

    /** GET /api/v1/attribute-synonyms */
    public function synonymsIndex(Request $request): JsonResponse
    {
        $query = AttributeSynonym::query();
        if ($key = $request->input('attribute_key')) {
            $query->where('attribute_key', $key);
        }
        return response()->json(['data' => $query->orderBy('attribute_key')->orderBy('word')->get()]);
    }

    /** POST /api/v1/attribute-synonyms */
    public function synonymsStore(Request $request, AttributeExtractionService $service): JsonResponse
    {
        $data = $request->validate([
            'attribute_key'    => 'nullable|string|max:60',
            'word'             => 'required|string|max:200',
            'normalized_value' => 'required|string|max:200',
        ]);

        $s = AttributeSynonym::updateOrCreate(
            ['attribute_key' => $data['attribute_key'] ?? null, 'word' => mb_strtolower(trim($data['word']))],
            ['normalized_value' => $data['normalized_value']]
        );

        $service->clearCache();
        return response()->json($s, 201);
    }

    /** DELETE /api/v1/attribute-synonyms/{id} */
    public function synonymsDestroy(int $id, AttributeExtractionService $service): JsonResponse
    {
        AttributeSynonym::findOrFail($id)->delete();
        $service->clearCache();
        return response()->json(['message' => 'Удалено']);
    }

    // ─────────────────────────────────────────────────────────────────
    // AUDIT
    // ─────────────────────────────────────────────────────────────────

    /**
     * GET /api/v1/attribute-rules/audit
     * Returns a summary of attribute coverage in the DB for each key.
     */
    public function audit(): JsonResponse
    {
        $stats = \App\Models\ProductAttribute::query()
            ->selectRaw('attr_name, attr_type, COUNT(*) as count, COUNT(DISTINCT attr_value) as unique_values, AVG(confidence) as avg_confidence')
            ->groupBy('attr_name', 'attr_type')
            ->orderByDesc('count')
            ->get();

        $topValues = \App\Models\ProductAttribute::query()
            ->selectRaw('attr_name, attr_value, COUNT(*) as cnt, AVG(confidence) as avg_conf')
            ->groupBy('attr_name', 'attr_value')
            ->orderByDesc('cnt')
            ->limit(300)
            ->get()
            ->groupBy('attr_name');

        $result = $stats->map(function ($row) use ($topValues) {
            return [
                'attr_name'      => $row->attr_name,
                'attr_type'      => $row->attr_type,
                'count'          => $row->count,
                'unique_values'  => $row->unique_values,
                'avg_confidence' => round((float) $row->avg_confidence, 2),
                'top_values'     => ($topValues[$row->attr_name] ?? collect())
                    ->take(10)
                    ->map(fn($v) => [
                        'value'      => $v->attr_value,
                        'count'      => $v->cnt,
                        'avg_conf'   => round((float) $v->avg_conf, 2),
                    ]),
            ];
        });

        return response()->json([
            'total_products'           => \App\Models\Product::count(),
            'products_with_attributes' => \App\Models\ProductAttribute::distinct('product_id')->count(),
            'total_attribute_rows'     => \App\Models\ProductAttribute::count(),
            'attributes'               => $result,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // DICTIONARY
    // ─────────────────────────────────────────────────────────────────

    /** GET /api/v1/attribute-dictionary */
    public function dictionaryIndex(Request $request): JsonResponse
    {
        $query = AttributeDictionary::query();
        if ($key = $request->input('attribute_key')) {
            $query->where('attribute_key', $key);
        }
        return response()->json(['data' => $query->orderBy('attribute_key')->orderBy('sort_order')->get()]);
    }

    /** POST /api/v1/attribute-dictionary */
    public function dictionaryStore(Request $request, AttributeExtractionService $service): JsonResponse
    {
        $data = $request->validate([
            'attribute_key' => 'required|string|max:60',
            'value'         => 'required|string|max:300',
            'sort_order'    => 'nullable|integer|min:0|max:9999',
        ]);

        $entry = AttributeDictionary::updateOrCreate(
            ['attribute_key' => $data['attribute_key'], 'value' => $data['value']],
            ['sort_order' => $data['sort_order'] ?? 100]
        );
        $service->clearCache();
        return response()->json($entry, 201);
    }

    /** PATCH /api/v1/attribute-dictionary/{id} */
    public function dictionaryUpdate(Request $request, int $id, AttributeExtractionService $service): JsonResponse
    {
        $entry = AttributeDictionary::findOrFail($id);
        $data  = $request->validate([
            'value'      => 'sometimes|string|max:300',
            'sort_order' => 'sometimes|integer|min:0|max:9999',
        ]);
        $entry->update($data);
        $service->clearCache();
        return response()->json($entry->fresh());
    }

    /** DELETE /api/v1/attribute-dictionary/{id} */
    public function dictionaryDestroy(int $id, AttributeExtractionService $service): JsonResponse
    {
        AttributeDictionary::findOrFail($id)->delete();
        $service->clearCache();
        return response()->json(['message' => 'Удалено']);
    }

    // ─────────────────────────────────────────────────────────────────
    // CANONICAL (attribute_value_normalization)
    // ─────────────────────────────────────────────────────────────────

    /** GET /api/v1/attribute-canonical */
    public function canonicalIndex(Request $request): JsonResponse
    {
        $query = AttributeValueNormalization::query();
        if ($key = $request->input('attribute_key')) {
            $query->where('attribute_key', $key);
        }
        if ($search = $request->input('search')) {
            $query->where(fn($q) =>
                $q->where('raw_value', 'LIKE', "%{$search}%")
                  ->orWhere('normalized_value', 'LIKE', "%{$search}%")
            );
        }
        return response()->json(['data' => $query->orderBy('attribute_key')->orderBy('raw_value')->paginate(100)]);
    }

    /** POST /api/v1/attribute-canonical */
    public function canonicalStore(Request $request, AttributeExtractionService $service): JsonResponse
    {
        $data = $request->validate([
            'attribute_key'    => 'required|string|max:60',
            'raw_value'        => 'required|string|max:300',
            'normalized_value' => 'required|string|max:300',
        ]);
        $data['raw_value'] = mb_strtolower(trim($data['raw_value']));

        $entry = AttributeValueNormalization::updateOrCreate(
            ['attribute_key' => $data['attribute_key'], 'raw_value' => $data['raw_value']],
            ['normalized_value' => $data['normalized_value']]
        );
        $service->clearCache();
        return response()->json($entry, 201);
    }

    /** PATCH /api/v1/attribute-canonical/{id} */
    public function canonicalUpdate(Request $request, int $id, AttributeExtractionService $service): JsonResponse
    {
        $entry = AttributeValueNormalization::findOrFail($id);
        $data  = $request->validate(['normalized_value' => 'required|string|max:300']);
        $entry->update($data);
        $service->clearCache();
        return response()->json($entry->fresh());
    }

    /** DELETE /api/v1/attribute-canonical/{id} */
    public function canonicalDestroy(int $id, AttributeExtractionService $service): JsonResponse
    {
        AttributeValueNormalization::findOrFail($id)->delete();
        $service->clearCache();
        return response()->json(['message' => 'Удалено']);
    }

    // ─────────────────────────────────────────────────────────────────
    // FACETS
    // ─────────────────────────────────────────────────────────────────

    /**
     * GET /api/v1/attribute-facets?category_id=X
     * Returns faceted filter data for a category (or global).
     */
    public function facets(Request $request, AttributeFacetService $facetService): JsonResponse
    {
        $categoryId = $request->input('category_id') ? (int) $request->input('category_id') : null;
        $minConf    = (float) ($request->input('min_confidence', 0.6));
        $data = $facetService->getFacetsByCategory($categoryId, $minConf);
        return response()->json(['data' => $data, 'category_id' => $categoryId]);
    }

    /**
     * POST /api/v1/attribute-facets/rebuild?category_id=X
     * Force-rebuild cached facets for a category.
     */
    public function facetsRebuild(Request $request, AttributeFacetService $facetService): JsonResponse
    {
        $categoryId = $request->input('category_id') ? (int) $request->input('category_id') : null;
        $data = $facetService->rebuildCategoryFacets($categoryId);
        return response()->json(['data' => $data, 'category_id' => $categoryId]);
    }
}
