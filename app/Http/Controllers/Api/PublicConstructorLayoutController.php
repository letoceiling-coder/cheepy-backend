<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConstructorLayoutTemplate;
use App\Models\ConstructorLayoutTemplateBlock;
use Illuminate\Http\JsonResponse;

class PublicConstructorLayoutController extends Controller
{
    private function emptyResponse(): array
    {
        return [
            'template_key' => null,
            'updated_at' => null,
            'blocks' => [],
        ];
    }

    private function mapTemplate(ConstructorLayoutTemplate $tpl): array
    {
        return [
            'template_key' => $tpl->template_key,
            'updated_at' => $tpl->updated_at?->toIso8601String(),
            'blocks' => $tpl->blocks
                ->map(fn (ConstructorLayoutTemplateBlock $b) => [
                    'block_type' => $b->block_type,
                    'settings' => $b->settings ?? [],
                    'is_enabled' => (bool) $b->is_enabled,
                    'is_visible' => (bool) $b->is_visible,
                    'sort_order' => (int) $b->sort_order,
                ])
                ->values(),
        ];
    }

    /**
     * GET /api/v1/public/layout/global
     * Возвращает активный глобальный layout (Header/Footer/MobileBottomNav) для витрины.
     */
    public function global(): JsonResponse
    {
        $tpl = ConstructorLayoutTemplate::query()
            ->where('page_scope', 'global')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->with(['blocks' => fn ($q) => $q->orderBy('sort_order')])
            ->first();

        if (! $tpl) {
            return response()->json($this->emptyResponse());
        }

        return response()->json($this->mapTemplate($tpl));
    }

    /**
     * GET /api/v1/public/layout/page/{pageKey}
     * Возвращает активный page-template (content blocks) для указанного ключа страницы.
     */
    public function page(string $pageKey): JsonResponse
    {
        $key = trim($pageKey);
        if ($key === '') {
            return response()->json($this->emptyResponse());
        }

        $query = ConstructorLayoutTemplate::query()
            ->where('page_scope', 'page')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderByDesc('id');

        $tpl = (clone $query)
            ->where('page_key', $key)
            ->with(['blocks' => fn ($q) => $q->orderBy('sort_order')])
            ->first();

        if (! $tpl) {
            $tpl = $query
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->with(['blocks' => fn ($q) => $q->orderBy('sort_order')])
            ->first();
        }

        if (! $tpl) {
            return response()->json($this->emptyResponse());
        }

        return response()->json($this->mapTemplate($tpl));
    }
}

