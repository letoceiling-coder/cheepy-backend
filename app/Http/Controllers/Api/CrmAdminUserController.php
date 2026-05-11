<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * JWT-CRM: управление аккаунтами админ-панели ({@see AdminUser}).
 */
class CrmAdminUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min(100, (int) $request->query('per_page', 50)));
        $query = AdminUser::query()->with('roles')->orderByDesc('id');

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%');
            });
        }

        $paginator = $query->paginate($perPage);
        $data = collect($paginator->items())->map(fn (AdminUser $u) => $this->payload($u))->values()->all();

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
            'name' => ['required', 'string', 'max:200'],
            'email' => ['required', 'string', 'max:200', 'email', 'unique:admin_users,email'],
            'password' => ['required', 'string', 'min:6', 'max:255'],
            'role' => ['nullable', 'string', 'in:admin,editor,viewer'],
            'is_active' => ['nullable', 'boolean'],
            'role_ids' => ['nullable', 'array', 'max:50'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
        ]);

        $payload = [
            'name' => trim($data['name']),
            'email' => strtolower(trim($data['email'])),
            'password' => Hash::make($data['password']),
            'role' => $data['role'] ?? 'editor',
            'is_active' => $data['is_active'] ?? true,
        ];

        $user = DB::transaction(function () use ($payload, $data): AdminUser {
            $admin = AdminUser::query()->create($payload);
            $ids = isset($data['role_ids']) ? array_values(array_unique(array_map('intval', $data['role_ids']))) : [];
            if ($ids !== []) {
                $admin->roles()->sync($ids);
            }

            return $admin->load('roles');
        });

        return response()->json($this->payload($user), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $admin = AdminUser::query()->findOrFail($id);

        $rules = [
            'name' => ['sometimes', 'string', 'max:200'],
            'email' => ['sometimes', 'string', 'max:200', 'email', Rule::unique('admin_users', 'email')->ignore($admin->id)],
            'password' => ['sometimes', 'nullable', 'string', 'min:6', 'max:255'],
            'role' => ['sometimes', 'string', 'in:admin,editor,viewer'],
            'is_active' => ['sometimes', 'boolean'],
            'role_ids' => ['sometimes', 'array', 'max:50'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
        ];

        /** @var array<string, mixed> $data */
        $data = $request->validate($rules);

        if (array_key_exists('name', $data)) {
            $admin->name = trim((string) $data['name']);
        }
        if (array_key_exists('email', $data)) {
            $admin->email = strtolower(trim((string) $data['email']));
        }
        if (! empty($data['password'])) {
            $admin->password = Hash::make((string) $data['password']);
        }
        if (array_key_exists('role', $data)) {
            $admin->role = (string) $data['role'];
        }
        if (array_key_exists('is_active', $data)) {
            $admin->is_active = (bool) $data['is_active'];
        }

        DB::transaction(function () use ($admin, $data): void {
            $admin->save();
            if (array_key_exists('role_ids', $data)) {
                /** @var list<int|string> $raw */
                $raw = $data['role_ids'] ?? [];
                $ids = array_values(array_unique(array_map('intval', $raw)));
                $admin->roles()->sync($ids);
            }
        });

        return response()->json($this->payload($admin->fresh()->load('roles')));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        /** @var AdminUser|null $actor */
        $actor = $request->attributes->get('auth_user_model');
        if ($actor instanceof AdminUser && $actor->id === $id) {
            return response()->json(['error' => 'Нельзя удалить свою собственную учётную запись'], 422);
        }

        $remainingAdmins = AdminUser::query()->where('role', 'admin')->where('is_active', true)->count();
        $target = AdminUser::query()->findOrFail($id);
        if ($target->role === 'admin' && $target->is_active && $remainingAdmins <= 1) {
            return response()->json(['error' => 'Нельзя удалить последнего активного администратора'], 422);
        }

        $target->delete();

        return response()->json(['ok' => true]);
    }

    /** @return array<string, mixed> */
    private function payload(AdminUser $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'role' => $u->role,
            'is_active' => (bool) $u->is_active,
            'roles' => $u->roles->map(fn (Role $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'slug' => $r->slug,
            ])->values()->all(),
            'created_at' => $u->created_at?->toIso8601String(),
            'updated_at' => $u->updated_at?->toIso8601String(),
        ];
    }
}
