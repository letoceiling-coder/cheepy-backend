<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StorefrontCartSnapshot;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Снимок корзины витрины (для напоминаний и валидации товаров перед письмом).
 */
class StorefrontCartSyncController extends Controller
{
    public function sync(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('storefront_user');
        $data = $request->validate([
            'items' => ['required', 'array', 'max:80'],
            'items.*.product_id' => ['required', 'string', 'max:190'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'items.*.color' => ['nullable', 'string', 'max:120'],
            'items.*.size' => ['nullable', 'string', 'max:120'],
        ]);

        $items = [];
        foreach ($data['items'] as $row) {
            $items[] = [
                'product_id' => trim((string) $row['product_id']),
                'quantity' => (int) $row['quantity'],
                'color' => isset($row['color']) ? trim((string) $row['color']) : null,
                'size' => isset($row['size']) ? trim((string) $row['size']) : null,
            ];
        }

        if ($items === []) {
            StorefrontCartSnapshot::query()->where('user_id', $user->id)->delete();

            return response()->json(['ok' => true, 'items_count' => 0]);
        }

        StorefrontCartSnapshot::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'items' => array_values($items),
                'last_abandon_email_at' => null,
            ]
        );

        return response()->json(['ok' => true, 'items_count' => count($items)]);
    }
}
