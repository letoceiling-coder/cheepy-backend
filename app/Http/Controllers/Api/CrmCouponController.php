<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CrmCouponController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = Coupon::query()
            ->latest()
            ->paginate((int) $request->query('per_page', 50));

        return response()->json([
            'data' => $rows->getCollection()->map(fn (Coupon $coupon) => $this->payload($coupon))->values(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
            ],
            'analytics' => $this->analytics(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $coupon = Coupon::query()->create($data);

        return response()->json($this->payload($coupon), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $coupon = Coupon::query()->findOrFail($id);
        $data = $this->validated($request, $id);
        $coupon->update($data);

        return response()->json($this->payload($coupon->refresh()));
    }

    public function analytics(): array
    {
        return [
            'total' => Coupon::query()->count(),
            'active' => Coupon::query()->where('is_active', true)->count(),
            'used_count' => (int) CouponRedemption::query()->count(),
            'discount_amount' => (int) CouponRedemption::query()->sum('discount_amount'),
        ];
    }

    private function validated(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:64', 'unique:coupons,code'.($id ? ','.$id : '')],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'discount_type' => ['required', 'string', 'in:percent,fixed'],
            'discount_value' => ['required', 'integer', 'min:1'],
            'min_order_amount' => ['nullable', 'integer', 'min:0'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'max_uses_per_user' => ['nullable', 'integer', 'min:1'],
            'target' => ['nullable', 'string', 'max:40'],
            'is_active' => ['nullable', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'rules' => ['nullable', 'array'],
        ]);
    }

    private function payload(Coupon $coupon): array
    {
        return [
            ...$coupon->toArray(),
            'redemptions_count' => CouponRedemption::query()->where('coupon_id', $coupon->id)->count(),
            'discount_amount' => (int) CouponRedemption::query()->where('coupon_id', $coupon->id)->sum('discount_amount'),
        ];
    }
}
