<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttributeRule;
use App\Models\AttributeSynonym;
use App\Models\Product;
use App\Services\AttributeExtractionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttributeRuleController extends Controller
{
    public function __construct(private AttributeExtractionService $service) {}

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
        $rules = $query->orderBy('attribute_key')->orderBy('priority')->get();

        return response()->json(['data' => $rules]);
    }

    /** POST /api/v1/attribute-rules */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'attribute_key' => 'required|string|max:60',
            'display_name'  => 'required|string|max:120',
            'rule_type'     => 'required|in:regex,keyword',
            'pattern'       => 'required|string|max:500',
            'apply_synonyms'=> 'boolean',
            'attr_type'     => 'required|in:text,size,color,number',
            'priority'      => 'integer|min:1|max:999',
            'enabled'       => 'boolean',
        ]);

        if ($data['rule_type'] === 'regex') {
            $this->validateRegex($data['pattern']);
        }

        $rule = AttributeRule::create($data);
        $this->service->clearCache();

        return response()->json($rule, 201);
    }

    /** PATCH /api/v1/attribute-rules/{id} */
    public function update(Request $request, int $id): JsonResponse
    {
        $rule = AttributeRule::findOrFail($id);
        $data = $request->validate([
            'attribute_key' => 'sometimes|string|max:60',
            'display_name'  => 'sometimes|string|max:120',
            'rule_type'     => 'sometimes|in:regex,keyword',
            'pattern'       => 'sometimes|string|max:500',
            'apply_synonyms'=> 'sometimes|boolean',
            'attr_type'     => 'sometimes|in:text,size,color,number',
            'priority'      => 'sometimes|integer|min:1|max:999',
            'enabled'       => 'sometimes|boolean',
        ]);

        if (isset($data['pattern']) && ($data['rule_type'] ?? $rule->rule_type) === 'regex') {
            $this->validateRegex($data['pattern']);
        }

        $rule->update($data);
        $this->service->clearCache();

        return response()->json($rule->fresh());
    }

    /** DELETE /api/v1/attribute-rules/{id} */
    public function destroy(int $id): JsonResponse
    {
        AttributeRule::findOrFail($id)->delete();
        $this->service->clearCache();
        return response()->json(['message' => 'Правило удалено']);
    }

    // ─────────────────────────────────────────────────────────────────
    // SYNONYMS
    // ─────────────────────────────────────────────────────────────────

    /** GET /api/v1/attribute-rules/synonyms */
    public function synonyms(Request $request): JsonResponse
    {
        $query = AttributeSynonym::query();
        if ($key = $request->input('attribute_key')) {
            $query->where('attribute_key', $key);
        }
        return response()->json(['data' => $query->orderBy('attribute_key')->orderBy('word')->get()]);
    }

    /** POST /api/v1/attribute-rules/synonyms */
    public function storeSynonym(Request $request): JsonResponse
    {
        $data = $request->validate([
            'attribute_key'   => 'nullable|string|max:60',
            'word'            => 'required|string|max:200',
            'normalized_value'=> 'required|string|max:200',
        ]);
        $synonym = AttributeSynonym::create($data);
        $this->service->clearCache();
        return response()->json($synonym, 201);
    }

    /** DELETE /api/v1/attribute-rules/synonyms/{id} */
    public function destroySynonym(int $id): JsonResponse
    {
        AttributeSynonym::findOrFail($id)->delete();
        $this->service->clearCache();
        return response()->json(['message' => 'Синоним удалён']);
    }

    // ─────────────────────────────────────────────────────────────────
    // TEST / REBUILD
    // ─────────────────────────────────────────────────────────────────

    /**
     * POST /api/v1/attribute-rules/test
     * Test extraction against arbitrary text without saving.
     */
    public function test(Request $request): JsonResponse
    {
        $data = $request->validate(['text' => 'required|string|max:5000']);
        $extracted = $this->service->extractFromText($data['text']);
        return response()->json(['extracted' => $extracted]);
    }

    /**
     * POST /api/v1/attribute-rules/rebuild
     * Rebuild product_attributes for all products (async-friendly: runs inline, could be queued).
     * Returns job stats.
     */
    public function rebuild(Request $request): JsonResponse
    {
        $productId = $request->input('product_id');

        if ($productId) {
            $product = Product::findOrFail((int) $productId);
            $attrs   = $this->service->extractAndSave($product);
            return response()->json([
                'product_id' => $product->id,
                'saved'      => count($attrs),
                'attributes' => $attrs,
            ]);
        }

        // Full rebuild — queue it if large dataset
        $total = Product::count();
        if ($total > 500) {
            dispatch(function () {
                app(AttributeExtractionService::class)->rebuildAll();
            })->afterResponse();

            return response()->json(['message' => 'Пересборка запущена в фоне', 'total' => $total]);
        }

        $result = $this->service->rebuildAll();
        return response()->json(array_merge($result, ['message' => 'Пересборка завершена']));
    }

    // ─────────────────────────────────────────────────────────────────
    // PRIVATE
    // ─────────────────────────────────────────────────────────────────

    private function validateRegex(string $pattern): void
    {
        $result = @preg_match('/' . $pattern . '/iu', '');
        if ($result === false) {
            abort(422, 'Некорректный regex: ' . (preg_last_error_msg() ?? 'unknown'));
        }
    }
}
