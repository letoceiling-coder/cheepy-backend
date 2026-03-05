<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AdminUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AdminUser::query()->with('roles');
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                    ->orWhere('email', 'like', "%{$s}%");
            });
        }
        $perPage = min((int) $request->get('per_page', 20), 100);
        $paginated = $query->orderBy('id')->paginate($perPage);
        return response()->json([
            'data' => $paginated->items(),
            'meta' => [
                'total' => $paginated->total(),
                'per_page' => $paginated->perPage(),
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:200',
            'email' => 'required|email|unique:admin_users,email',
            'password' => 'required|string|min:8',
            'role' => 'nullable|string|in:admin,editor,viewer',
            'is_active' => 'boolean',
            'role_ids' => 'array',
            'role_ids.*' => 'exists:roles,id',
        ]);
        $data['password'] = Hash::make($data['password']);
        $data['role'] = $data['role'] ?? 'editor';
        $data['is_active'] = $data['is_active'] ?? true;
        $roleIds = $data['role_ids'] ?? [];
        unset($data['role_ids']);
        $user = AdminUser::create($data);
        if (!empty($roleIds)) {
            $user->roles()->sync($roleIds);
        }
        $user->load('roles');
        return response()->json($user, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = AdminUser::findOrFail($id);
        $data = $request->validate([
            'name' => 'sometimes|string|max:200',
            'email' => ['sometimes', 'email', Rule::unique('admin_users')->ignore($user->id)],
            'password' => 'nullable|string|min:8',
            'role' => 'nullable|string|in:admin,editor,viewer',
            'is_active' => 'boolean',
            'role_ids' => 'array',
            'role_ids.*' => 'exists:roles,id',
        ]);
        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }
        if (array_key_exists('role_ids', $data)) {
            $user->roles()->sync($data['role_ids']);
            unset($data['role_ids']);
        }
        $user->update($data);
        $user->load('roles');
        return response()->json($user);
    }

    public function destroy(int $id): JsonResponse
    {
        $user = AdminUser::findOrFail($id);
        $user->roles()->detach();
        $user->delete();
        return response()->json(null, 204);
    }
}
