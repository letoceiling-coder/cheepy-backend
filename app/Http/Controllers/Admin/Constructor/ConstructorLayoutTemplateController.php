<?php

namespace App\Http\Controllers\Admin\Constructor;

use App\Http\Controllers\Controller;
use App\Models\ConstructorLayoutTemplate;
use App\Models\ConstructorLayoutTemplateBlock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConstructorLayoutTemplateController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = ConstructorLayoutTemplate::query()
            ->withCount('blocks')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $rows->map(fn (ConstructorLayoutTemplate $t) => [
                'id' => $t->id,
                'template_key' => $t->template_key,
                'name' => $t->name,
                'description' => $t->description,
                'is_system' => $t->is_system,
                'sort_order' => $t->sort_order,
                'blocks_count' => $t->blocks_count,
                'updated_at' => $t->updated_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $tpl = ConstructorLayoutTemplate::with(['blocks' => fn ($q) => $q->orderBy('sort_order')])->findOrFail($id);

        return response()->json($this->detail($tpl));
    }

    /**
     * Пользовательский шаблон + начальный набор блоков (опционально).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'blocks' => 'nullable|array',
            'blocks.*.block_type' => 'required|string|max:120',
            'blocks.*.settings' => 'nullable|array',
            'blocks.*.sort_order' => 'nullable|integer|min:0|max:2147483647',
            'blocks.*.client_key' => 'nullable|string|max:64',
            'blocks.*.is_visible' => 'nullable|boolean',
        ]);

        $key = 'custom:'.Str::lower(Str::uuid()->toString());

        $tpl = DB::transaction(function () use ($data, $key) {
            $tpl = ConstructorLayoutTemplate::create([
                'template_key' => $key,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_system' => false,
                'sort_order' => 9000,
            ]);

            $this->replaceBlocks($tpl, $data['blocks'] ?? []);

            return $tpl->fresh(['blocks' => fn ($q) => $q->orderBy('sort_order')]);
        });

        return response()->json($this->detail($tpl), 201);
    }

    /**
     * Полная замена блоков шаблона (системные и пользовательские — для JWT-админки).
     */
    public function syncBlocks(Request $request, int $id): JsonResponse
    {
        $tpl = ConstructorLayoutTemplate::findOrFail($id);

        $data = $request->validate([
            'blocks' => 'required|array',
            'blocks.*.block_type' => 'required|string|max:120',
            'blocks.*.settings' => 'nullable|array',
            'blocks.*.sort_order' => 'nullable|integer|min:0|max:2147483647',
            'blocks.*.client_key' => 'nullable|string|max:64',
            'blocks.*.is_visible' => 'nullable|boolean',
        ]);

        DB::transaction(function () use ($tpl, $data) {
            $this->replaceBlocks($tpl, $data['blocks']);
        });

        return response()->json($this->detail($tpl->fresh(['blocks' => fn ($q) => $q->orderBy('sort_order')])));
    }

    public function destroy(int $id): JsonResponse
    {
        $tpl = ConstructorLayoutTemplate::findOrFail($id);
        if ($tpl->is_system) {
            return response()->json(['message' => 'Системный шаблон нельзя удалить'], 422);
        }
        $tpl->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     */
    private function replaceBlocks(ConstructorLayoutTemplate $tpl, array $blocks): void
    {
        ConstructorLayoutTemplateBlock::query()->where('constructor_layout_template_id', $tpl->id)->delete();

        foreach ($blocks as $i => $row) {
            if (! is_array($row) || empty($row['block_type'])) {
                continue;
            }
            $settings = $row['settings'] ?? [];
            if (! is_array($settings)) {
                $settings = [];
            }
            ConstructorLayoutTemplateBlock::create([
                'constructor_layout_template_id' => $tpl->id,
                'block_type' => $row['block_type'],
                'sort_order' => $row['sort_order'] ?? ($i * 10),
                'settings' => $settings,
                'client_key' => $row['client_key'] ?? null,
                'is_visible' => $row['is_visible'] ?? true,
            ]);
        }
    }

    private function detail(ConstructorLayoutTemplate $tpl): array
    {
        $tpl->loadMissing(['blocks' => fn ($q) => $q->orderBy('sort_order')]);

        return [
            'id' => $tpl->id,
            'template_key' => $tpl->template_key,
            'name' => $tpl->name,
            'description' => $tpl->description,
            'is_system' => $tpl->is_system,
            'sort_order' => $tpl->sort_order,
            'updated_at' => $tpl->updated_at?->toIso8601String(),
            'blocks' => $tpl->blocks->map(fn (ConstructorLayoutTemplateBlock $b) => [
                'id' => $b->id,
                'block_type' => $b->block_type,
                'sort_order' => $b->sort_order,
                'settings' => $b->settings ?? [],
                'client_key' => $b->client_key,
                'is_visible' => $b->is_visible,
            ]),
        ];
    }
}
