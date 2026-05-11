<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * JWT-CRM: справочник ролей RBAC для привязки к {@see \App\Models\AdminUser}.
 */
class CrmAdminRoleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min(200, (int) $request->query('per_page', 100)));
        $paginator = Role::query()->withCount('users')->orderBy('slug')->paginate($perPage);
        $data = collect($paginator->items())->map(fn (Role $r) => [
            'id' => $r->id,
            'name' => $r->name,
            'slug' => $r->slug,
            'users_count' => (int) ($r->users_count ?? 0),
        ])->values()->all();

        return response()->json([
            'data' => $data,
            'meta' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => max(1, $paginator->lastPage()),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9][a-z0-9_-]*$/', Rule::unique('roles', 'slug')],
        ]);

        $slug = strtolower(trim($data['slug']));
        $role = Role::query()->create([
            'name' => trim($data['name']),
            'slug' => $slug,
        ]);

        return response()->json([
            'id' => $role->id,
            'name' => $role->name,
            'slug' => $role->slug,
            'users_count' => 0,
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $role = Role::query()->findOrFail($id);
        $reserved = ['admin', 'editor', 'viewer'];

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'slug' => [
                'sometimes',
                'string',
                'max:100',
                'regex:/^[a-z0-9][a-z0-9_-]*$/',
                Rule::unique('roles', 'slug')->ignore($role->id),
            ],
        ]);

        if (isset($data['slug'])) {
            $newSlug = strtolower(trim($data['slug']));
            if (in_array($role->slug, $reserved, true) && $newSlug !== $role->slug) {
                return response()->json(['error' => 'Нельзя менять slug у системной роли'], 422);
            }
            $role->slug = $newSlug;
        }
        if (isset($data['name'])) {
            $role->name = trim($data['name']);
        }
        $role->save();

        $role->loadCount('users');

        return response()->json([
            'id' => $role->id,
            'name' => $role->name,
            'slug' => $role->slug,
            'users_count' => (int) $role->users_count,
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $role = Role::query()->findOrFail($id);
        if (in_array($role->slug, ['admin', 'editor', 'viewer'], true)) {
            return response()->json(['error' => 'Нельзя удалить системную роль'], 422);
        }
        if ($role->users()->exists()) {
            return response()->json(['error' => 'Сначала отвяжите пользователей от этой роли'], 422);
        }

        $role->delete();

        return response()->json(['ok' => true]);
    }
}
