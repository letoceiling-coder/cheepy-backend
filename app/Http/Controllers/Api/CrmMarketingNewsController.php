<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MarketingNews;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CrmMarketingNewsController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = MarketingNews::query()->orderByDesc('published_at')->orderByDesc('id')->limit(300)->get();

        return response()->json([
            'data' => $rows->map(fn (MarketingNews $n) => $this->row($n)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request, true);
        $data['slug'] = $this->uniqueSlug($data['slug'] ?? null, $data['title']);

        $n = MarketingNews::query()->create($data);

        return response()->json(['data' => $this->row($n)], 201);
    }

    public function show(int $id): JsonResponse
    {
        $n = MarketingNews::query()->findOrFail($id);

        return response()->json(['data' => $this->row($n)]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $n = MarketingNews::query()->findOrFail($id);
        $data = $this->validatePayload($request, false);
        if (array_key_exists('slug', $data) && $data['slug'] !== null && trim((string) $data['slug']) !== '') {
            $data['slug'] = $this->uniqueSlug((string) $data['slug'], $data['title'] ?? $n->title, $n->id);
        } elseif (array_key_exists('slug', $data)) {
            unset($data['slug']);
        }
        if ($data !== []) {
            $n->update($data);
        }
        $n->refresh();

        return response()->json(['data' => $this->row($n)]);
    }

    public function destroy(int $id): JsonResponse
    {
        MarketingNews::query()->whereKey($id)->delete();

        return response()->json(['ok' => true]);
    }

    /** @param  array<string, mixed>|null  $exceptId */
    private function uniqueSlug(?string $rawSlug, ?string $title, ?int $exceptId = null): string
    {
        $base = trim((string) ($rawSlug !== '' && $rawSlug !== null ? $rawSlug : (string) $title));
        if ($base === '') {
            $base = 'news';
        }
        $slug = Str::slug(Str::substr($base, 0, 150)) ?: ('news-'.now()->unix());
        $orig = $slug;
        $i = 2;
        while (MarketingNews::query()
            ->where('slug', $slug)
            ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))
            ->exists()) {
            $slug = $orig.'-'.$i++;
        }

        return $slug;
    }

    /** @return array<string, mixed> */
    private function validatePayload(Request $request, bool $creating): array
    {
        $rules = [
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:240'],
            'body' => [$creating ? 'required' : 'sometimes', 'string'],
            'slug' => ['nullable', 'string', 'max:160'],
            'image_url' => ['nullable', 'string', 'max:1024'],
            'video_url' => ['nullable', 'string', 'max:1024'],
            'file_url' => ['nullable', 'string', 'max:1024'],
            'file_label' => ['nullable', 'string', 'max:200'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65534'],
            'published_at' => ['nullable', 'date'],
        ];

        return $request->validate($rules);
    }

    /** @return array<string, mixed> */
    private function row(MarketingNews $n): array
    {
        return [
            'id' => $n->id,
            'slug' => $n->slug,
            'title' => $n->title,
            'body' => $n->body,
            'image_url' => $n->image_url,
            'video_url' => $n->video_url,
            'file_url' => $n->file_url,
            'file_label' => $n->file_label,
            'is_active' => (bool) $n->is_active,
            'sort_order' => (int) $n->sort_order,
            'published_at' => $n->published_at?->toIso8601String(),
            'updated_at' => $n->updated_at?->toIso8601String(),
        ];
    }
}
