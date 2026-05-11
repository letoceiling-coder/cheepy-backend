<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Storefront\StorefrontReferralAttributionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Публичная фиксация клика по реферальной ссылке (без JWT).
 */
class StorefrontReferralTrackingController extends Controller
{
    /**
     * Ответ всегда одинаковый по форме (без раскрытия валидности кода).
     */
    public function track(Request $request, StorefrontReferralAttributionService $attribution): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:48'],
            'visitor_id' => ['nullable', 'string', 'max:128'],
        ]);

        $attribution->recordClick(
            $data['code'],
            $data['visitor_id'] ?? null,
            $request->userAgent(),
        );

        return response()->json(['ok' => true]);
    }
}
