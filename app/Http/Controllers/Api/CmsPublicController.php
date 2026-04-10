<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CmsPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Публичная выдача CMS-страниц для витрины (блоки + SEO).
 */
class CmsPublicController extends Controller
{
    public function showByPath(Request $request, string $pathPrefix, string $slug): JsonResponse
    {
        $pathPrefix = strtolower(trim($pathPrefix));
        $slug = trim($slug);

        $page = CmsPage::query()
            ->where('path_prefix', $pathPrefix)
            ->where('slug', $slug)
            ->first();

        if (! $page || ! $page->is_active) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if ($page->status !== CmsPage::STATUS_PUBLISHED || $page->published_version_id === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $version = $page->publishedVersion;
        if (! $version) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $version->load(['blocks' => fn ($q) => $q->orderBy('sort_order')]);

        return response()->json($this->formatPayload($page, $version));
    }

    private function formatPayload(CmsPage $page, \App\Models\CmsPageVersion $version): array
    {
        return [
            'page' => [
                'id' => $page->id,
                'page_key' => $page->page_key,
                'page_type' => $page->page_type,
                'path_prefix' => $page->path_prefix,
                'slug' => $page->slug,
                'title' => $page->title,
                'seo' => [
                    'title' => $page->seo_title,
                    'description' => $page->seo_description,
                    'og_title' => $page->og_title,
                    'og_description' => $page->og_description,
                    'og_image_url' => $page->og_image_url,
                    'canonical_url' => $page->canonical_url,
                    'robots' => $page->robots,
                    'extra' => $page->seo_extra ?? [],
                ],
            ],
            'blocks' => $version->blocks->map(fn ($b) => [
                'block_type' => $b->block_type,
                'sort_order' => $b->sort_order,
                'settings' => $b->settings ?? [],
                'client_key' => $b->client_key,
                'is_visible' => $b->is_visible,
            ])->values()->all(),
        ];
    }
}
