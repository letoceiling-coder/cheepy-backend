<?php

namespace App\Http\Controllers\Admin\Constructor;

use App\Http\Controllers\Controller;
use App\Models\ConstructorLayoutTemplate;
use App\Models\ConstructorLayoutTemplateBlock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ConstructorLayoutTemplateController extends Controller
{
    private const FORBIDDEN_PAGE_SCOPE_TYPES = ['Header', 'Footer'];

    public function index(): JsonResponse
    {
        $q = ConstructorLayoutTemplate::query()
            ->withCount('blocks')
            ->orderBy('sort_order')
            ->orderBy('name');

        request()->whenFilled('template_type', fn ($value) => $q->where('template_type', (string) $value));
        request()->whenFilled('page_scope', fn ($value) => $q->where('page_scope', (string) $value));
        request()->whenFilled('page_key', fn ($value) => $q->where('page_key', (string) $value));
        if (request()->has('is_active')) {
            $q->where('is_active', filter_var(request('is_active'), FILTER_VALIDATE_BOOL));
        }

        $rows = $q->get();

        return response()->json([
            'data' => $rows->map(fn (ConstructorLayoutTemplate $t) => [
                'id' => $t->id,
                'template_key' => $t->template_key,
                'name' => $t->name,
                'description' => $t->description,
                'template_type' => $t->template_type,
                'page_scope' => $t->page_scope,
                'page_key' => $t->page_key,
                'is_system' => $t->is_system,
                'is_editable' => $t->is_editable,
                'is_active' => $t->is_active,
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
            'template_type' => 'nullable|in:system,content',
            'page_scope' => 'nullable|in:page,global',
            'page_key' => 'nullable|string|max:128',
            'is_active' => 'nullable|boolean',
            'blocks' => 'nullable|array',
            'blocks.*.block_type' => 'required|string|max:120',
            'blocks.*.settings' => 'nullable|array',
            'blocks.*.sort_order' => 'nullable|integer|min:0|max:2147483647',
            'blocks.*.client_key' => 'nullable|string|max:64',
            'blocks.*.is_enabled' => 'nullable|boolean',
            'blocks.*.is_visible' => 'nullable|boolean',
            'blocks.*.is_required' => 'nullable|boolean',
            'blocks.*.is_locked' => 'nullable|boolean',
            'blocks.*.slot_key' => 'nullable|string|max:80',
        ]);

        $key = 'custom:'.Str::lower(Str::uuid()->toString());
        $templateType = (string) ($data['template_type'] ?? 'content');
        $pageScope = (string) ($data['page_scope'] ?? 'page');
        $pageKey = $data['page_key'] ?? null;
        $isActive = (bool) ($data['is_active'] ?? true);

        $tpl = DB::transaction(function () use ($data, $key, $templateType, $pageScope, $pageKey, $isActive) {
            $tpl = ConstructorLayoutTemplate::create([
                'template_key' => $key,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'template_type' => $templateType,
                'page_scope' => $pageScope,
                'page_key' => $pageKey,
                'is_system' => $templateType === 'system',
                'is_editable' => true,
                'is_active' => $isActive,
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
        if (! $tpl->is_editable) {
            return response()->json(['message' => 'Шаблон заблокирован для редактирования'], 422);
        }

        $data = $request->validate([
            'blocks' => 'required|array',
            'blocks.*.block_type' => 'required|string|max:120',
            'blocks.*.settings' => 'nullable|array',
            'blocks.*.sort_order' => 'nullable|integer|min:0|max:2147483647',
            'blocks.*.client_key' => 'nullable|string|max:64',
            'blocks.*.is_enabled' => 'nullable|boolean',
            'blocks.*.is_visible' => 'nullable|boolean',
            'blocks.*.is_required' => 'nullable|boolean',
            'blocks.*.is_locked' => 'nullable|boolean',
            'blocks.*.slot_key' => 'nullable|string|max:80',
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
        $this->assertBlockTypesAllowed($tpl, $blocks);
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
                'is_enabled' => isset($row['is_enabled']) ? (bool) $row['is_enabled'] : (($row['is_visible'] ?? true) === true),
                'is_visible' => isset($row['is_enabled']) ? (bool) $row['is_enabled'] : (($row['is_visible'] ?? true) === true),
                'is_required' => (bool) ($row['is_required'] ?? false),
                'is_locked' => (bool) ($row['is_locked'] ?? false),
                'slot_key' => $row['slot_key'] ?? null,
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
            'template_type' => $tpl->template_type,
            'page_scope' => $tpl->page_scope,
            'page_key' => $tpl->page_key,
            'is_system' => $tpl->is_system,
            'is_editable' => $tpl->is_editable,
            'is_active' => $tpl->is_active,
            'sort_order' => $tpl->sort_order,
            'updated_at' => $tpl->updated_at?->toIso8601String(),
            'blocks' => $tpl->blocks->map(fn (ConstructorLayoutTemplateBlock $b) => [
                'id' => $b->id,
                'block_type' => $b->block_type,
                'sort_order' => $b->sort_order,
                'settings' => $b->settings ?? [],
                'client_key' => $b->client_key,
                'is_enabled' => $b->is_enabled,
                'is_visible' => $b->is_visible,
                'is_required' => $b->is_required,
                'is_locked' => $b->is_locked,
                'slot_key' => $b->slot_key,
            ]),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     */
    private function assertBlockTypesAllowed(ConstructorLayoutTemplate $tpl, array $blocks): void
    {
        if ($tpl->page_scope !== 'page') {
            return;
        }
        foreach ($blocks as $row) {
            $type = (string) ($row['block_type'] ?? '');
            if (in_array($type, self::FORBIDDEN_PAGE_SCOPE_TYPES, true)) {
                throw ValidationException::withMessages([
                    'blocks' => ["Блок {$type} запрещён в page-шаблонах. Настраивайте его в глобальном шаблоне."],
                ]);
            }
        }
    }
}
