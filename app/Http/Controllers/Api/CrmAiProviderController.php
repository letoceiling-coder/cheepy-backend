<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiProviderIntegration;
use App\Support\AiProviderCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CrmAiProviderController extends Controller
{
    /** Обновление каталога моделей на сервере — см. App\Support\AiProviderCatalog */
    private const CATALOG_UPDATED_AT = '2026-04-30';

    public function index(): JsonResponse
    {
        $keys = AiProviderCatalog::providerKeys();
        $rows = AiProviderIntegration::query()
            ->whereIn('name', $keys)
            ->get()
            ->sortBy(fn (AiProviderIntegration $r) => array_search($r->name, $keys, true))
            ->values();

        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->serializeProvider($row->name, $row);
        }

        return response()->json(['data' => $out, 'catalog_updated_at' => self::CATALOG_UPDATED_AT]);
    }

    public function update(Request $request, string $name): JsonResponse
    {
        if (! in_array($name, AiProviderCatalog::providerKeys(), true)) {
            abort(404);
        }

        $row = AiProviderIntegration::where('name', $name)->firstOrFail();

        $data = $request->validate([
            'is_active' => 'nullable|boolean',
            'api_key' => 'nullable|string|max:8192',
            'default_model' => 'nullable|string|max:512',
        ]);

        if (array_key_exists('is_active', $data)) {
            $row->is_active = (bool) $data['is_active'];
        }

        $config = $row->config ?? [];

        if (array_key_exists('api_key', $data)) {
            $key = $data['api_key'];
            if ($key !== null && $key !== '' && $key !== '***') {
                $config['api_key'] = trim($key);
            }
        }

        if (array_key_exists('default_model', $data) && $data['default_model'] !== null && $data['default_model'] !== '') {
            $mid = trim($data['default_model']);
            if (! AiProviderCatalog::isValidModel($name, $mid)) {
                return response()->json(['error' => 'Неизвестная модель для этого провайдера'], 422);
            }
            $config['default_model'] = $mid;
        }

        $row->config = $config;
        $row->save();

        return response()->json($this->serializeProvider($name, $row->fresh()));
    }

    public function clearKey(string $name): JsonResponse
    {
        if (! in_array($name, AiProviderCatalog::providerKeys(), true)) {
            abort(404);
        }

        $row = AiProviderIntegration::where('name', $name)->firstOrFail();
        $config = $row->config ?? [];
        unset($config['api_key']);
        $row->config = $config;
        $row->save();

        return response()->json($this->serializeProvider($name, $row->fresh()));
    }

    private function serializeProvider(string $name, AiProviderIntegration $row): array
    {
        $meta = AiProviderCatalog::meta($name);
        $config = $row->config ?? [];
        $hasKey = ! empty($config['api_key']);
        $defaultModel = (string) ($config['default_model'] ?? AiProviderCatalog::defaultModel($name));
        if (! AiProviderCatalog::isValidModel($name, $defaultModel)) {
            $defaultModel = AiProviderCatalog::defaultModel($name);
        }

        return [
            'name' => $name,
            'title' => $meta['title'] ?? $name,
            'description' => $meta['description'] ?? '',
            'docs_url' => $meta['docs_url'] ?? '',
            'is_active' => $row->is_active,
            'has_api_key' => $hasKey,
            'api_key_hint' => $hasKey ? $this->maskKeyTail((string) $config['api_key']) : null,
            'default_model' => $defaultModel,
            'models' => AiProviderCatalog::models($name),
            'catalog_updated_at' => self::CATALOG_UPDATED_AT,
        ];
    }

    private function maskKeyTail(string $key): string
    {
        $key = trim($key);
        if (strlen($key) <= 4) {
            return '••••';
        }

        return '…'.substr($key, -4);
    }
}
