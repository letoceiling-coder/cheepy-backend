<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BrandController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Brand::query();
        if ($search = $request->input('search')) {
            $query->where('name', 'like', "%{$search}%");
        }
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        $brands = $query->orderBy('name')->paginate($request->input('per_page', 20));
        return response()->json(['data' => $brands->items(), 'total' => $brands->total()]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(Brand::findOrFail($id));
    }

    public function store(Request $request): JsonResponse
    {
        $brand = Brand::create([
            'name' => $request->input('name'),
            'slug' => $request->input('slug', Str::slug($request->input('name', ''))),
            'logo_url' => $request->input('logo_url'),
            'status' => $request->input('status', 'active'),
            'seo_title' => $request->input('seo_title'),
            'seo_description' => $request->input('seo_description'),
            'category_ids' => $request->input('category_ids', []),
        ]);
        return response()->json($brand, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $brand = Brand::findOrFail($id);
        $brand->update($request->only(['name', 'slug', 'logo_url', 'status', 'seo_title', 'seo_description', 'category_ids']));
        return response()->json($brand->fresh());
    }

    public function destroy(int $id): JsonResponse
    {
        Brand::findOrFail($id)->delete();
        return response()->json(['message' => 'Удалено']);
    }
}
