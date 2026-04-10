<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CrmMediaFile;
use App\Models\CrmMediaFolder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * CRM медиабиблиотека: папки, файлы, корзина. Не затрагивает парсер / products.
 */
class CrmMediaController extends Controller
{
    public function folders(Request $request): JsonResponse
    {
        $parentId = $request->input('parent_id');
        $q = CrmMediaFolder::query()->orderBy('sort_order')->orderBy('name');
        if ($parentId === null || $parentId === '') {
            $q->whereNull('parent_id');
        } else {
            $q->where('parent_id', (int) $parentId);
        }
        $items = $q->get()->map(fn (CrmMediaFolder $f) => $this->formatFolder($f));

        return response()->json(['data' => $items]);
    }

    public function storeFolder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'parent_id' => 'nullable|integer|exists:crm_media_folders,id',
        ]);

        $trash = CrmMediaFolder::trashFolder();
        if ($trash && (int) ($data['parent_id'] ?? 0) === (int) $trash->id) {
            return response()->json(['message' => 'Нельзя создавать папки внутри корзины'], 422);
        }

        $slug = $this->uniqueSlug(Str::slug($data['name']) ?: 'folder');

        $folder = CrmMediaFolder::create([
            'parent_id' => $data['parent_id'] ?? null,
            'name' => $data['name'],
            'slug' => $slug,
            'is_system' => false,
            'sort_order' => 0,
        ]);

        return response()->json($this->formatFolder($folder), 201);
    }

    public function updateFolder(Request $request, int $id): JsonResponse
    {
        $folder = CrmMediaFolder::findOrFail($id);
        if ($folder->is_system) {
            return response()->json(['message' => 'Системную папку нельзя переименовать'], 422);
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $folder->update([
            'name' => $data['name'],
            'slug' => $this->uniqueSlug(Str::slug($data['name']) ?: 'folder', $folder->id),
        ]);

        return response()->json($this->formatFolder($folder->fresh()));
    }

    public function destroyFolder(int $id): JsonResponse
    {
        $folder = CrmMediaFolder::findOrFail($id);
        if ($folder->is_system || $folder->slug === CrmMediaFolder::SLUG_TRASH) {
            return response()->json(['message' => 'Эту папку нельзя удалить'], 422);
        }
        if ($folder->children()->exists()) {
            return response()->json(['message' => 'Сначала удалите вложенные папки'], 422);
        }
        if ($folder->files()->exists()) {
            return response()->json(['message' => 'Сначала переместите или удалите файлы'], 422);
        }

        $folder->delete();

        return response()->json(['message' => 'Удалено'], 204);
    }

    public function files(Request $request): JsonResponse
    {
        $folderId = (int) $request->input('folder_id');
        $trash = CrmMediaFolder::trashFolder();
        if (!$trash) {
            return response()->json(['data' => [], 'meta' => ['total' => 0]], 200);
        }

        if ($folderId !== (int) $trash->id) {
            CrmMediaFolder::findOrFail($folderId);
        }

        if ($folderId === (int) $trash->id) {
            $q = CrmMediaFile::query()->where('folder_id', $trash->id);
        } else {
            $q = CrmMediaFile::query()->where('folder_id', $folderId)->whereNull('restore_folder_id');
        }

        if ($search = $request->input('search')) {
            $q->where('original_name', 'like', '%'.$search.'%');
        }
        if ($mime = $request->input('mime')) {
            $q->where('mime_type', 'like', $mime.'%');
        }

        $perPage = min((int) $request->input('per_page', 40), 100);
        $paginator = $q->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (CrmMediaFile $f) => $this->formatFile($f))->values(),
            'meta' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function upload(Request $request): JsonResponse
    {
        // max в килобайтах; должно быть ≤ upload_max_filesize / post_max_size в php.ini
        $data = $request->validate([
            'folder_id' => 'required|integer|exists:crm_media_folders,id',
            'files' => 'required|array|min:1|max:50',
            'files.*' => 'file|max:'.(1024 * 100),
        ]);

        $trash = CrmMediaFolder::trashFolder();
        if ($trash && (int) $data['folder_id'] === (int) $trash->id) {
            return response()->json(['message' => 'Нельзя загружать файлы напрямую в корзину'], 422);
        }

        $created = [];
        foreach ($request->file('files', []) as $file) {
            if (!$file || !$file->isValid()) {
                continue;
            }
            $original = $file->getClientOriginalName();
            $safe = preg_replace('/[^a-zA-Z0-9._\-]/u', '_', $original) ?: 'file';
            $name = Str::lower(Str::random(8)).'_'.$safe;
            $relative = 'crm-media/'.$data['folder_id'].'/'.$name;
            Storage::disk('public')->put($relative, file_get_contents($file->getRealPath()));

            $created[] = CrmMediaFile::create([
                'folder_id' => (int) $data['folder_id'],
                'path' => $relative,
                'original_name' => mb_substr($original, 0, 500),
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'restore_folder_id' => null,
            ]);
        }

        return response()->json([
            'data' => collect($created)->map(fn (CrmMediaFile $f) => $this->formatFile($f))->values(),
        ], 201);
    }

    public function moveFiles(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file_ids' => 'required|array|min:1',
            'file_ids.*' => 'integer|exists:crm_media_files,id',
            'folder_id' => 'required|integer|exists:crm_media_folders,id',
        ]);

        $trash = CrmMediaFolder::trashFolder();
        $targetId = (int) $data['folder_id'];

        foreach ($data['file_ids'] as $fid) {
            $file = CrmMediaFile::find((int) $fid);
            if (!$file) {
                continue;
            }
            if ($trash && $targetId !== (int) $trash->id && $file->restore_folder_id !== null) {
                $file->update([
                    'folder_id' => $targetId,
                    'restore_folder_id' => null,
                ]);

                continue;
            }
            if ($trash && $targetId === (int) $trash->id) {
                $file->update([
                    'restore_folder_id' => $file->folder_id,
                    'folder_id' => $trash->id,
                ]);

                continue;
            }
            $file->update([
                'folder_id' => $targetId,
                'restore_folder_id' => null,
            ]);
        }

        return response()->json(['message' => 'OK']);
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        $file = CrmMediaFile::findOrFail($id);
        $trash = CrmMediaFolder::trashFolder();
        if (!$trash || (int) $file->folder_id !== (int) $trash->id) {
            return response()->json(['message' => 'Файл не в корзине'], 422);
        }

        $to = $file->restore_folder_id;
        if ($to && CrmMediaFolder::query()->whereKey($to)->exists()) {
            $file->update([
                'folder_id' => $to,
                'restore_folder_id' => null,
            ]);
        } else {
            $root = CrmMediaFolder::query()->whereNull('parent_id')->where('slug', '!=', CrmMediaFolder::SLUG_TRASH)->orderBy('id')->first();
            $file->update([
                'folder_id' => $root?->id ?? $file->folder_id,
                'restore_folder_id' => null,
            ]);
        }

        return response()->json($this->formatFile($file->fresh()));
    }

    public function emptyTrash(): JsonResponse
    {
        $trash = CrmMediaFolder::trashFolder();
        if (!$trash) {
            return response()->json(['message' => 'OK']);
        }

        $files = CrmMediaFile::query()->where('folder_id', $trash->id)->get();
        foreach ($files as $file) {
            if (Storage::disk('public')->exists($file->path)) {
                Storage::disk('public')->delete($file->path);
            }
            $file->delete();
        }

        return response()->json(['message' => 'Корзина очищена']);
    }

    private function formatFolder(CrmMediaFolder $f): array
    {
        return [
            'id' => $f->id,
            'parent_id' => $f->parent_id,
            'name' => $f->name,
            'slug' => $f->slug,
            'is_system' => (bool) $f->is_system,
            'sort_order' => (int) $f->sort_order,
        ];
    }

    private function formatFile(CrmMediaFile $f): array
    {
        return [
            'id' => $f->id,
            'folder_id' => $f->folder_id,
            'original_name' => $f->original_name,
            'mime_type' => $f->mime_type,
            'size_bytes' => (int) $f->size_bytes,
            'url' => $f->publicUrl(),
            'restore_folder_id' => $f->restore_folder_id,
        ];
    }

    private function uniqueSlug(string $base, ?int $exceptId = null): string
    {
        $slug = $base;
        $i = 0;
        while (CrmMediaFolder::query()
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->where('slug', $slug)->exists()) {
            $i++;
            $slug = $base.'-'.$i;
        }

        return mb_substr($slug, 0, 64);
    }
}
