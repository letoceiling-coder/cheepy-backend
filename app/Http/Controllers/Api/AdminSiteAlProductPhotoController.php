<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Прокси проверки фото карточки (site-al vision). Ключ только на сервере.
 *
 * POST /api/v1/admin/site-al/product-photos/verify
 */
class AdminSiteAlProductPhotoController extends Controller
{
    /** Совпадает с дефолтом site-al PRODUCT_PHOTO_VERIFY_MAX_ITEMS (24). */
    private const MAX_PHOTOS_PER_REQUEST = 24;

    public function verify(Request $request): JsonResponse
    {
        $request->merge([
            'productName' => trim((string) $request->input('productName', '')),
        ]);

        $validated = $request->validate([
            'productName' => ['required', 'string', 'min:1', 'max:500'],
            'description' => ['nullable', 'string', 'max:50000'],
            'color' => ['nullable', 'string', 'max:200'],
            'photos' => ['required', 'array', 'min:1', 'max:'.self::MAX_PHOTOS_PER_REQUEST],
            'photos.*.url' => ['required', 'string', 'max:2000'],
            'options' => ['nullable', 'array'],
            'options.minConfidence' => ['nullable', 'numeric', 'between:0,1'],
            'options.concurrency' => ['nullable', 'integer', 'between:1,6'],
            'options.language' => ['nullable', 'string', 'in:ru,en'],
        ]);

        $baseUrl = rtrim((string) config('services.site_al.base_url'), '/');
        $apiKey = config('services.site_al.api_key');

        if ($baseUrl === '' || ! is_string($apiKey) || $apiKey === '') {
            return response()->json([
                'message' => 'Интеграция site-al не настроена: задайте SITE_AL_BASE_URL и SITE_AL_API_KEY в .env.',
            ], 503);
        }

        $photoRows = array_values($validated['photos']);
        $photoRows = array_map(function (array $p) {
            return ['url' => trim((string) ($p['url'] ?? ''))];
        }, $photoRows);
        $photoRows = array_values(array_filter($photoRows, fn ($p) => $p['url'] !== ''));
        $photoRows = array_values(array_filter(
            $photoRows,
            fn ($p) => (bool) preg_match('#^https://#i', $p['url'])
        ));

        if ($photoRows === []) {
            return response()->json([
                'message' => 'Нет ни одного URL с https:// в photos (внешний сервис качает картинки по ссылке).',
            ], 422);
        }

        $payload = [
            'productName' => $validated['productName'],
            'photos' => $photoRows,
        ];
        if (array_key_exists('description', $validated) && $validated['description'] !== null) {
            $payload['description'] = $validated['description'];
        }
        if (! empty($validated['color'])) {
            $payload['color'] = $validated['color'];
        }
        if (! empty($validated['options']) && is_array($validated['options'])) {
            $payload['options'] = $validated['options'];
        }

        $url = $baseUrl.'/product-photos/verify';
        $timeout = (int) config('services.site_al.photo_verify_timeout', 180);

        try {
            $response = Http::timeout($timeout)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'X-Api-Key' => $apiKey,
                ])
                ->post($url, $payload);
        } catch (\Throwable $e) {
            Log::warning('site-al product-photos verify transport error', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Не удалось связаться с сервисом проверки фото: '.$e->getMessage(),
            ], 502);
        }

        $body = $response->json();
        if (! $response->successful()) {
            $msg = is_array($body) && isset($body['error']) && is_string($body['error'])
                ? $body['error']
                : (is_array($body) && isset($body['message']) && is_string($body['message'])
                    ? $body['message']
                    : 'Сервис проверки фото вернул HTTP '.$response->status());

            Log::warning('site-al product-photos verify upstream error', [
                'status' => $response->status(),
                'body' => $body,
            ]);

            $out = is_array($body) ? $body : ['message' => $msg];
            if (! isset($out['message']) && $msg !== '') {
                $out['message'] = $msg;
            }

            return response()->json(
                $out,
                $response->status() >= 400 && $response->status() < 600 ? $response->status() : 502
            );
        }

        if (! is_array($body)) {
            return response()->json([
                'message' => 'Неожиданный ответ сервиса проверки фото (не JSON).',
            ], 502);
        }

        return response()->json($body);
    }
}
