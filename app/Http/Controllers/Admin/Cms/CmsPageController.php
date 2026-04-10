<?php

namespace App\Http\Controllers\Admin\Cms;

use App\Http\Controllers\Controller;
use App\Models\CmsPage;
use App\Models\CmsPageBlock;
use App\Models\CmsPageVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CmsPageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 30), 100);
        $q = CmsPage::query()->orderByDesc('updated_at');

        if ($request->filled('status')) {
            $q->where('status', $request->input('status'));
        }
        if ($request->has('is_active')) {
            $q->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOL));
        }
        if ($search = trim((string) $request->input('search', ''))) {
            $q->where(function ($w) use ($search) {
                $w->where('title', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('page_key', 'like', "%{$search}%");
            });
        }

        $paginated = $q->paginate($perPage);

        return response()->json([
            'data' => $paginated->getCollection()->map(fn (CmsPage $p) => $this->summary($p))->values(),
            'meta' => [
                'total' => $paginated->total(),
                'per_page' => $paginated->perPage(),
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $page = CmsPage::with(['versions' => fn ($q) => $q->orderByDesc('version_number')])->findOrFail($id);

        return response()->json($this->detail($page));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:500',
            'slug' => 'required|string|max:255|regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
            'path_prefix' => 'nullable|string|max:32|regex:/^[a-z0-9]+$/',
            'page_type' => 'nullable|string|max:32',
            'page_key' => 'nullable|string|max:255',
        ]);

        $pathPrefix = strtolower($data['path_prefix'] ?? 'p');
        $slug = $data['slug'];

        $this->assertPathPrefixAllowed($pathPrefix);
        $this->assertSlugNotReserved($slug);

        if (CmsPage::query()->where('path_prefix', $pathPrefix)->where('slug', $slug)->exists()) {
            return response()->json(['message' => 'Страница с таким URL уже есть'], 422);
        }

        $pageKey = $data['page_key'] ?? 'custom:'.$slug;
        if (CmsPage::query()->where('page_key', $pageKey)->exists()) {
            return response()->json(['message' => 'page_key уже занят'], 422);
        }

        $page = DB::transaction(function () use ($data, $pathPrefix, $slug, $pageKey) {
            $page = CmsPage::create([
                'page_key' => $pageKey,
                'page_type' => $data['page_type'] ?? 'custom',
                'path_prefix' => $pathPrefix,
                'slug' => $slug,
                'title' => $data['title'],
                'is_active' => true,
                'status' => CmsPage::STATUS_DRAFT,
                'published_version_id' => null,
            ]);

            $version = CmsPageVersion::create([
                'cms_page_id' => $page->id,
                'version_number' => 1,
                'status' => CmsPage::STATUS_DRAFT,
            ]);

            $page->update([]);

            return $page->fresh(['versions']);
        });

        $page->load('versions');

        return response()->json($this->detail($page), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $page = CmsPage::findOrFail($id);

        $data = $request->validate([
            'title' => 'sometimes|string|max:500',
            'is_active' => 'sometimes|boolean',
            'seo_title' => 'nullable|string|max:500',
            'seo_description' => 'nullable|string|max:512',
            'og_title' => 'nullable|string|max:500',
            'og_description' => 'nullable|string|max:512',
            'og_image_url' => 'nullable|string|max:1024',
            'canonical_url' => 'nullable|string|max:1024',
            'robots' => 'nullable|string|max:64',
            'seo_extra' => 'nullable|array',
        ]);

        $page->update($data);

        return response()->json($this->detail($page->fresh(['versions'])));
    }

    /**
     * Полная замена блоков версии. settings — произвольный JSON-объект на блок.
     */
    public function syncBlocks(Request $request, int $pageId, int $versionId): JsonResponse
    {
        $page = CmsPage::findOrFail($pageId);
        $version = CmsPageVersion::where('cms_page_id', $page->id)->whereKey($versionId)->firstOrFail();

        $data = $request->validate([
            'blocks' => 'required|array',
            'blocks.*.block_type' => 'required|string|max:120',
            'blocks.*.settings' => 'nullable|array',
            'blocks.*.sort_order' => 'nullable|integer|min:0|max:2147483647',
            'blocks.*.client_key' => 'nullable|string|max:64',
            'blocks.*.is_visible' => 'nullable|boolean',
        ]);

        DB::transaction(function () use ($version, $data) {
            CmsPageBlock::where('cms_page_version_id', $version->id)->delete();

            foreach ($data['blocks'] as $i => $row) {
                $settings = $row['settings'] ?? [];
                if (! is_array($settings)) {
                    $settings = [];
                }
                CmsPageBlock::create([
                    'cms_page_version_id' => $version->id,
                    'block_type' => $row['block_type'],
                    'sort_order' => $row['sort_order'] ?? ($i * 10),
                    'settings' => $settings,
                    'client_key' => $row['client_key'] ?? null,
                    'is_visible' => $row['is_visible'] ?? true,
                ]);
            }
        });

        $version->load(['blocks' => fn ($q) => $q->orderBy('sort_order')]);

        return response()->json([
            'version' => [
                'id' => $version->id,
                'version_number' => $version->version_number,
                'status' => $version->status,
                'blocks' => $version->blocks->map(fn ($b) => [
                    'id' => $b->id,
                    'block_type' => $b->block_type,
                    'sort_order' => $b->sort_order,
                    'settings' => $b->settings ?? [],
                    'client_key' => $b->client_key,
                    'is_visible' => $b->is_visible,
                ]),
            ],
        ]);
    }

    public function publish(int $id): JsonResponse
    {
        $page = CmsPage::findOrFail($id);

        $version = $page->versions()->orderByDesc('version_number')->first();
        if (! $version) {
            return response()->json(['message' => 'Нет версии страницы'], 422);
        }

        DB::transaction(function () use ($page, $version) {
            $version->update(['status' => CmsPage::STATUS_PUBLISHED]);
            $page->update([
                'status' => CmsPage::STATUS_PUBLISHED,
                'published_version_id' => $version->id,
            ]);
        });

        return response()->json($this->detail($page->fresh(['versions'])));
    }

    private function summary(CmsPage $p): array
    {
        return [
            'id' => $p->id,
            'page_key' => $p->page_key,
            'page_type' => $p->page_type,
            'path_prefix' => $p->path_prefix,
            'slug' => $p->slug,
            'title' => $p->title,
            'is_active' => $p->is_active,
            'status' => $p->status,
            'updated_at' => $p->updated_at?->toIso8601String(),
        ];
    }

    private function detail(CmsPage $page): array
    {
        $page->loadMissing(['versions' => fn ($q) => $q->orderByDesc('version_number')]);

        return [
            'id' => $page->id,
            'page_key' => $page->page_key,
            'page_type' => $page->page_type,
            'path_prefix' => $page->path_prefix,
            'slug' => $page->slug,
            'title' => $page->title,
            'is_active' => $page->is_active,
            'status' => $page->status,
            'published_version_id' => $page->published_version_id,
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
            'versions' => $page->versions->map(fn (CmsPageVersion $v) => [
                'id' => $v->id,
                'version_number' => $v->version_number,
                'status' => $v->status,
            ]),
        ];
    }

    public function showVersion(int $pageId, int $versionId): JsonResponse
    {
        $page = CmsPage::findOrFail($pageId);
        $version = CmsPageVersion::where('cms_page_id', $page->id)->whereKey($versionId)->firstOrFail();
        $version->load(['blocks' => fn ($q) => $q->orderBy('sort_order')]);

        return response()->json([
            'page_id' => $page->id,
            'version' => [
                'id' => $version->id,
                'version_number' => $version->version_number,
                'status' => $version->status,
                'blocks' => $version->blocks->map(fn (CmsPageBlock $b) => [
                    'id' => $b->id,
                    'block_type' => $b->block_type,
                    'sort_order' => $b->sort_order,
                    'settings' => $b->settings ?? [],
                    'client_key' => $b->client_key,
                    'is_visible' => $b->is_visible,
                ]),
            ],
        ]);
    }

    private function assertPathPrefixAllowed(string $pathPrefix): void
    {
        $reserved = config('cms.reserved_path_prefixes', []);
        if (in_array($pathPrefix, $reserved, true)) {
            throw ValidationException::withMessages(['path_prefix' => ['Зарезервированный префикс пути']]);
        }
    }

    private function assertSlugNotReserved(string $slug): void
    {
        $reserved = config('cms.reserved_path_prefixes', []);
        if (in_array(strtolower($slug), $reserved, true)) {
            throw ValidationException::withMessages(['slug' => ['Зарезервированный slug']]);
        }
    }
}
