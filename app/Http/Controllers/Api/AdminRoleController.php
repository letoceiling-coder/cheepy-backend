<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminRoleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Role::query()->withCount('users');
        $perPage = min((int) $request->get('per_page', 50), 100);
        $paginated = $query->orderBy('name')->paginate($perPage);
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
            'name' => 'required|string|max:100',
            'slug' => 'required|string|max:100|unique:roles,slug',
        ]);
        $role = Role::create($data);
        return response()->json($role, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $role = Role::findOrFail($id);
        $data = $request->validate([
            'name' => 'sometimes|string|max:100',
            'slug' => ['sometimes', 'string', 'max:100', Rule::unique('roles')->ignore($role->id)],
        ]);
        $role->update($data);
        return response()->json($role);
    }

    public function destroy(int $id): JsonResponse
    {
        $role = Role::findOrFail($id);
        $role->users()->detach();
        $role->delete();
        return response()->json(null, 204);
    }
}
