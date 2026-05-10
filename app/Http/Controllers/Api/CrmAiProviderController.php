<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiProviderIntegration;
use App\Models\AiTokenUsageLog;
use App\Models\Setting;
use App\Support\AiProviderCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;

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

        $active = Setting::get('crm_active_ai_agent_provider');
        $activeStr = is_string($active) && $active !== '' ? $active : 'site_al';

        return response()->json([
            'data' => $out,
            'catalog_updated_at' => self::CATALOG_UPDATED_AT,
            'active_agent_provider' => $activeStr,
            'active_agent_options' => $this->activeAgentOptions(),
        ]);
    }

    public function setActiveAgent(Request $request): JsonResponse
    {
        $allowed = array_merge(['site_al'], AiProviderCatalog::agentChatProviderKeys());
        $data = $request->validate([
            'provider' => ['required', 'string', 'max:64', Rule::in($allowed)],
        ]);

        Setting::set('crm_active_ai_agent_provider', $data['provider'], 'crm');

        return response()->json([
            'active_agent_provider' => $data['provider'],
            'active_agent_options' => $this->activeAgentOptions(),
        ]);
    }

    public function tokenUsage(Request $request): JsonResponse
    {
        $perPage = min(100, max(10, (int) $request->query('per_page', 40)));
        $page = max(1, (int) $request->query('page', 1));

        $paginator = AiTokenUsageLog::query()
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => collect($paginator->items())->map(function (AiTokenUsageLog $row) {
                return [
                    'id' => $row->id,
                    'provider' => $row->provider,
                    'model' => $row->model,
                    'prompt_tokens' => $row->prompt_tokens,
                    'completion_tokens' => $row->completion_tokens,
                    'total_tokens' => $row->total_tokens,
                    'cost_usd' => $row->cost_usd !== null ? (string) $row->cost_usd : null,
                    'created_at' => $row->created_at?->toIso8601String(),
                ];
            })->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * GET /api/v1/crm/ai-providers/ollama/models
     * Список моделей с сохранённого OpenAI-compatible Ollama (Bearer из CRM).
     */
    public function ollamaModels(): JsonResponse
    {
        $row = AiProviderIntegration::where('name', 'ollama')->firstOrFail();
        $config = $row->config ?? [];

        $base = trim((string) ($config['base_url'] ?? ''));
        if ($base === '') {
            $base = rtrim((string) config('services.ollama.base_url', ''), '/');
        } else {
            $base = rtrim($base, '/');
        }

        $token = trim((string) ($config['api_key'] ?? ''));
        if ($token === '') {
            return response()->json([
                'message' => 'Сохраните Token для Ollama в CRM → Интеграции → ИИ, затем обновите список моделей.',
            ], 422);
        }

        $url = $base.'/models';

        try {
            $response = Http::timeout(45)
                ->withHeaders([
                    'Authorization' => 'Bearer '.$token,
                    'Accept' => 'application/json',
                ])
                ->get($url);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Не удалось запросить Ollama ('.$url.'): '.$e->getMessage(),
            ], 502);
        }

        $body = $response->json();

        if (! $response->successful()) {
            $msg = is_array($body) && isset($body['message']) && is_string($body['message'])
                ? $body['message']
                : ('Ollama вернула HTTP '.$response->status());

            return response()->json([
                'message' => $msg,
                'details' => $body ?? $response->body(),
            ], $response->status() >= 400 && $response->status() < 600 ? $response->status() : 502);
        }

        $ids = [];

        // OpenAI-совместимый ответ { data: [{ id, object }] }
        if (is_array($body) && isset($body['data']) && is_array($body['data'])) {
            foreach ($body['data'] as $item) {
                if (is_array($item) && isset($item['id']) && is_string($item['id'])) {
                    $id = trim($item['id']);
                    if ($id !== '') {
                        $ids[] = $id;
                    }
                }
            }
        }

        $ids = array_values(array_unique($ids));
        natcasesort($ids);

        $out = [];
        foreach ($ids as $id) {
            $out[] = ['id' => $id, 'label' => $id];
        }

        return response()->json([
            'data' => array_values($out),
            'endpoint' => $url,
        ]);
    }

    /**
     * GET /api/v1/crm/ai-providers/openrouter/models
     * Полный каталог моделей OpenRouter + признак «бесплатная» (:free или нулевая текстовая цена).
     */
    public function openrouterModels(): JsonResponse
    {
        $row = AiProviderIntegration::where('name', 'openrouter')->firstOrFail();
        $config = $row->config ?? [];

        $base = trim((string) ($config['base_url'] ?? ''));
        if ($base === '') {
            $base = rtrim((string) config('services.openrouter.base_url', ''), '/');
        } else {
            $base = rtrim($base, '/');
        }

        $token = trim((string) ($config['api_key'] ?? ''));
        $publicBase = rtrim((string) config('services.openrouter.base_url', 'https://openrouter.ai/api/v1'), '/');
        $catalogSource = $token !== '' ? 'authenticated' : 'public';

        $url = $token !== '' ? ($base.'/models') : ($publicBase.'/models');

        try {
            $headers = [
                'Accept' => 'application/json',
            ];
            if ($token !== '') {
                $headers['Authorization'] = 'Bearer '.$token;
                $referer = rtrim((string) config('app.url', ''), '/');
                if ($referer !== '') {
                    $headers['Referer'] = $referer;
                }
                $title = trim((string) config('app.name', ''));
                if ($title !== '') {
                    $headers['X-Title'] = $title;
                }
            } else {
                $headers['User-Agent'] = 'Cheepy-CRM/OpenRouter-catalog (public /v1/models)';
            }

            $response = Http::timeout(120)
                ->withHeaders($headers)
                ->get($url);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Не удалось запросить OpenRouter ('.$url.'): '.$e->getMessage(),
            ], 502);
        }

        $body = $response->json();

        if (! $response->successful()) {
            $msg = is_array($body) && isset($body['error']['message']) && is_string($body['error']['message'])
                ? $body['error']['message']
                : ('OpenRouter вернул HTTP '.$response->status());

            return response()->json([
                'message' => $msg,
                'details' => $body ?? $response->body(),
            ], $response->status() >= 400 && $response->status() < 600 ? $response->status() : 502);
        }

        $out = [];
        if (! is_array($body) || ! isset($body['data']) || ! is_array($body['data'])) {
            return response()->json(['data' => [], 'endpoint' => $url, 'catalog_source' => $catalogSource]);
        }

        foreach ($body['data'] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $id = isset($item['id']) ? trim((string) $item['id']) : '';
            if ($id === '') {
                continue;
            }

            $nameRaw = isset($item['name']) ? trim((string) $item['name']) : '';
            $baseLabel = $nameRaw !== '' ? $nameRaw.' ('.$id.')' : $id;

            $free = $this->openRouterRowLooksFree($id, $item['pricing'] ?? null);

            $out[] = [
                'id' => $id,
                'label' => $baseLabel.($free ? ' — бесплатно' : ''),
                'free' => $free,
            ];
        }

        $chainRank = array_flip(AiProviderCatalog::openRouterFreeFallbackChain());
        usort($out, static function (array $a, array $b) use ($chainRank): int {
            $af = ! empty($a['free']);
            $bf = ! empty($b['free']);
            if ($af !== $bf) {
                return $af ? -1 : 1;
            }
            if ($af && $bf) {
                $ia = $chainRank[$a['id']] ?? 1000;
                $ib = $chainRank[$b['id']] ?? 1000;
                if ($ia !== $ib) {
                    return $ia <=> $ib;
                }
            }

            return strnatcasecmp((string) $a['label'], (string) $b['label']);
        });

        return response()->json([
            'data' => array_values($out),
            'endpoint' => $url,
            'catalog_source' => $catalogSource,
        ]);
    }

    /** @param mixed $pricing OpenRouter: prompt / completion — строки с ценой за токен или 0. */
    private function openRouterRowLooksFree(string $id, $pricing): bool
    {
        if ($id !== '' && str_ends_with($id, ':free')) {
            return true;
        }

        if (! is_array($pricing)) {
            return false;
        }

        $toNum = static function ($v): ?float {
            if ($v === null) {
                return null;
            }
            if (is_bool($v)) {
                return null;
            }
            if (is_numeric($v)) {
                return (float) $v;
            }
            if (is_string($v)) {
                $t = trim($v);
                if ($t === '' || $t === '-') {
                    return null;
                }
                if (is_numeric($t)) {
                    return (float) $t;
                }
            }

            return null;
        };

        $p = $toNum($pricing['prompt'] ?? null);
        $c = $toNum($pricing['completion'] ?? null);

        if ($p === null && $c === null) {
            return false;
        }

        return (($p ?? 0) <= 0.0) && (($c ?? 0) <= 0.0);
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
            'base_url' => 'nullable|string|max:512',
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

        if (array_key_exists('base_url', $data) && $data['base_url'] !== null) {
            $bu = trim((string) $data['base_url']);
            if ($bu === '') {
                unset($config['base_url']);
            } else {
                $config['base_url'] = $bu;
            }
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
            'agent_chat_capable' => in_array($name, AiProviderCatalog::agentChatProviderKeys(), true),
            'has_api_key' => $hasKey,
            'api_key_hint' => $hasKey ? $this->maskKeyTail((string) $config['api_key']) : null,
            'default_model' => $defaultModel,
            'base_url' => trim((string) ($config['base_url'] ?? '')),
            'models' => AiProviderCatalog::models($name),
            'catalog_updated_at' => self::CATALOG_UPDATED_AT,
        ];
    }

    /**
     * @return list<array{value: string, title: string, description: string}>
     */
    private function activeAgentOptions(): array
    {
        $opts = [
            [
                'value' => 'site_al',
                'title' => 'Site-al',
                'description' => 'Внешний агент (переменные SITE_AL_* на сервере). Диалоги с conversationId.',
            ],
        ];
        foreach (AiProviderCatalog::agentChatProviderKeys() as $key) {
            $meta = AiProviderCatalog::meta($key);
            $opts[] = [
                'value' => $key,
                'title' => (string) ($meta['title'] ?? $key),
                'description' => (string) ($meta['description'] ?? ''),
            ];
        }

        return $opts;
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
