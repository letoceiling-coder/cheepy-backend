<?php

namespace App\Services;

use App\Models\AiProviderIntegration;
use App\Models\AiTokenUsageLog;
use App\Models\Setting;
use App\Support\AiProviderCatalog;
use App\Support\OpenRouterFreeModelCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * CRM «Агент»: один вход POST /admin/site-al/chat, маршрутизация на site-al или прямой вызов выбранного LLM.
 */
class CrmAiAgentChatService
{
    private const RUSSIAN_OUTPUT_PREAMBLE = <<<'TXT'
КРИТИЧЕСКОЕ ТРЕБОВАНИЕ К ИТОГОВОМУ ОТВЕТУ:
— Пиши только по-русски: основной текст — кириллица, как на витрине маркетплейса в России.
— Латиница допустима только в уместных местах (маркировки размеров S/M/L, бренды из исходных данных, единицы измерения, если они уже в материале). Не добавляй новые английские предложения и служебные фразы.
— Запрещено: китайские, японские, корейские и прочие символы; фразы вроде «please rephrase», просьбы перейти на английский, «将需求», вставки вроде «冷水，请您…».
— Не отказывайся и не проси пользователя переформулировать запрос — просто выполни задачу по-русски.

Ниже — фактическое задание от оператора CRM:

TXT;

    /**
     * Поля для клиента CRM: какой провайдер ответил и id модели (если известно).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withAiMeta(array $payload, string $provider, ?string $modelId = null): array
    {
        $payload['ai_provider'] = $provider;
        $mid = $modelId !== null ? trim((string) $modelId) : '';
        if ($mid !== '') {
            $payload['ai_model'] = $mid;
        }

        return $payload;
    }

    /** @param array{message: string, conversationId?: string|null, agentId?: string|null, model?: string|null} $validated */
    public function chat(array $validated): JsonResponse
    {
        $provider = $this->resolveAgentProvider();

        return match ($provider) {
            'site_al' => $this->chatSiteAl($validated),
            'openrouter' => $this->chatOpenRouterOpenAiCompatible($validated),
            'openai', 'xai', 'ollama' => $this->chatOpenAiCompatible($provider, $validated),
            'anthropic' => $this->chatAnthropic($validated),
            'gemini' => $this->chatGemini($validated),
            default => $this->chatSiteAl($validated),
        };
    }

    private function resolveAgentProvider(): string
    {
        $v = Setting::get('crm_active_ai_agent_provider');
        if (! is_string($v) || $v === '') {
            return 'site_al';
        }

        $allowed = array_merge(['site_al'], AiProviderCatalog::agentChatProviderKeys());

        return in_array($v, $allowed, true) ? $v : 'site_al';
    }

    /** @param array{message: string, conversationId?: string|null, agentId?: string|null, model?: string|null} $validated */
    private function chatSiteAl(array $validated): JsonResponse
    {
        $baseUrl = rtrim((string) config('services.site_al.base_url'), '/');
        $apiKey = config('services.site_al.api_key');
        $defaultAgentId = config('services.site_al.agent_id');

        if ($baseUrl === '' || ! is_string($apiKey) || $apiKey === '') {
            return response()->json([
                'message' => 'Интеграция site-al не настроена: задайте SITE_AL_BASE_URL и SITE_AL_API_KEY в .env.',
            ], 503);
        }

        $agentId = $validated['agentId'] ?? $defaultAgentId;
        if (! is_string($agentId) || $agentId === '') {
            return response()->json([
                'message' => 'Не задан агент: укажите agentId в запросе или SITE_AL_AGENT_ID в .env.',
            ], 503);
        }

        $payload = [
            'agentId' => $agentId,
            'message' => self::RUSSIAN_OUTPUT_PREAMBLE.$validated['message'],
        ];
        if (! empty($validated['conversationId'])) {
            $payload['conversationId'] = $validated['conversationId'];
        }
        if (! empty($validated['model'])) {
            $payload['model'] = $validated['model'];
        }

        $url = $baseUrl.'/chat';

        try {
            $response = Http::timeout((int) config('services.site_al.timeout', 120))
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'X-Api-Key' => $apiKey,
                ])
                ->post($url, $payload);
        } catch (\Throwable $e) {
            Log::warning('site-al chat transport error', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Не удалось связаться с агентом: '.$e->getMessage(),
            ], 502);
        }

        $body = $response->json();
        if (! $response->successful()) {
            $msg = is_array($body) && isset($body['message']) && is_string($body['message'])
                ? $body['message']
                : 'Агент вернул ошибку HTTP '.$response->status();

            return response()->json([
                'message' => $msg,
                'details' => $body ?? $response->body(),
            ], $response->status() >= 400 && $response->status() < 600 ? $response->status() : 502);
        }

        if (! is_array($body)) {
            return response()->json([
                'message' => 'Неожиданный ответ агента (не JSON).',
            ], 502);
        }

        $reply = $body['reply'] ?? $body['text'] ?? $body['response'] ?? null;
        $conversationId = $body['conversationId'] ?? $body['conversation_id'] ?? null;
        $reqModel = isset($validated['model']) ? trim((string) $validated['model']) : '';

        return response()->json($this->withAiMeta([
            'reply' => is_string($reply) ? $reply : (is_scalar($reply) ? (string) $reply : null),
            'conversationId' => is_string($conversationId) ? $conversationId : null,
        ], 'site_al', $reqModel !== '' ? $reqModel : null));
    }

    /** @param array{message: string, model?: string|null} $validated */
    private function chatOpenRouterOpenAiCompatible(array $validated): JsonResponse
    {
        $provider = 'openrouter';
        $row = AiProviderIntegration::where('name', $provider)->first();
        if (! $row) {
            return response()->json(['message' => 'Интеграция openrouter не найдена в базе.'], 503);
        }

        $config = $row->config ?? [];
        $apiKey = trim((string) ($config['api_key'] ?? ''));
        if ($apiKey === '') {
            return response()->json([
                'message' => 'Для openrouter не сохранён API ключ в CRM → Интеграции → ИИ.',
            ], 503);
        }

        $primary = $this->pickOpenRouterModel($validated, $config);
        $candidates = $this->mergeOpenRouterCandidateModels($primary);
        $base = $this->openAiCompatibleBase($provider, $config);
        $url = $base.'/chat/completions';

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$apiKey,
        ];
        $ref = rtrim((string) config('app.url', ''), '/');
        if ($ref !== '') {
            $headers['Referer'] = $ref;
        }
        $title = trim((string) config('app.name', ''));
        if ($title !== '') {
            $headers['X-Title'] = $title;
        }

        $timeout = (int) config('services.openrouter.timeout', 120);
        $messages = [
            ['role' => 'user', 'content' => self::RUSSIAN_OUTPUT_PREAMBLE.$validated['message']],
        ];

        $lastStatus = 502;
        $lastBody = null;
        $lastMsg = '';

        foreach ($candidates as $model) {
            try {
                $response = Http::timeout($timeout)
                    ->withHeaders($headers)
                    ->post($url, [
                        'model' => $model,
                        'messages' => $messages,
                    ]);
            } catch (\Throwable $e) {
                Log::warning('crm ai chat transport', ['provider' => $provider, 'model' => $model, 'error' => $e->getMessage()]);
                $lastMsg = 'Не удалось связаться с openrouter: '.$e->getMessage();
                $lastStatus = 502;

                continue;
            }

            $body = $response->json();
            $lastBody = $body;
            $lastStatus = $response->status();

            if (! $response->successful()) {
                $lastMsg = $this->extractOpenAiStyleError($body) ?? ('OpenRouter вернул HTTP '.$response->status());
                if ($this->openRouterErrorShouldTryNextModel($response->status(), $body)) {
                    Log::info('openrouter model fallback', [
                        'model' => $model,
                        'http' => $response->status(),
                        'message' => $lastMsg,
                    ]);

                    continue;
                }

                return response()->json([
                    'message' => $lastMsg,
                    'details' => $body ?? $response->body(),
                ], $response->status() >= 400 && $response->status() < 600 ? $response->status() : 502);
            }

            if (! is_array($body)) {
                if ($this->openRouterErrorShouldTryNextModel(502, null)) {
                    continue;
                }

                return response()->json(['message' => 'Неожиданный ответ провайдера (не JSON).'], 502);
            }

            $text = $body['choices'][0]['message']['content'] ?? null;
            if (! is_string($text) || $text === '') {
                if ($this->openRouterErrorShouldTryNextModel(502, $body)) {
                    continue;
                }

                return response()->json(['message' => 'Пустой ответ модели.'], 502);
            }

            $usage = $body['usage'] ?? null;
            $this->maybeLogUsage($provider, $model, is_array($usage) ? $usage : null, 'openai_style');

            $payload = $this->withAiMeta([
                'reply' => $text,
                'conversationId' => null,
                'model_used' => $model,
            ], $provider, $model);
        }

        return response()->json([
            'message' => $lastMsg !== '' ? $lastMsg : 'Все доступные бесплатные модели недоступны. Подгрузите каталог в Интеграциях → ИИ и проверьте ключ OpenRouter.',
            'details' => $lastBody,
        ], $lastStatus >= 400 && $lastStatus < 600 ? $lastStatus : 502);
    }

    /**
     * @param  array{message: string, model?: string|null}  $validated
     * @param  array<string, mixed>  $config
     */
    private function pickOpenRouterModel(array $validated, array $config): string
    {
        $freeCache = OpenRouterFreeModelCache::get();
        $req = isset($validated['model']) ? trim((string) $validated['model']) : '';
        if ($req !== '' && AiProviderCatalog::isValidModel('openrouter', $req)) {
            return $this->resolveOpenRouterSingleFreeModelId($req, $freeCache);
        }

        $dm = trim((string) ($config['default_model'] ?? AiProviderCatalog::defaultModel('openrouter')));
        $chosen = AiProviderCatalog::isValidModel('openrouter', $dm)
            ? $dm
            : AiProviderCatalog::defaultModel('openrouter');

        return $this->resolveOpenRouterSingleFreeModelId($chosen, $freeCache);
    }

    /**
     * @param  list<string>  $freeCache
     */
    private function resolveOpenRouterSingleFreeModelId(string $id, array $freeCache): string
    {
        if (AiProviderCatalog::openRouterModelIsPersistableFree($id)) {
            return $id;
        }
        if ($freeCache !== [] && in_array($id, $freeCache, true)) {
            return $id;
        }

        return AiProviderCatalog::openRouterDefaultFreeModelId();
    }

    /**
     * @return list<string>
     */
    private function mergeOpenRouterCandidateModels(string $primaryModel): array
    {
        $freeCache = OpenRouterFreeModelCache::get();
        $merged = array_merge([$primaryModel], AiProviderCatalog::openRouterFreeFallbackChain());
        $out = [];
        foreach ($merged as $mid) {
            $mid = trim((string) $mid);
            if ($mid === '' || ! AiProviderCatalog::isValidModel('openrouter', $mid)) {
                continue;
            }
            if (! AiProviderCatalog::openRouterModelIsPersistableFree($mid) && ($freeCache === [] || ! in_array($mid, $freeCache, true))) {
                continue;
            }
            if (! in_array($mid, $out, true)) {
                $out[] = $mid;
            }
        }

        $max = max(1, (int) config('services.openrouter.max_chat_fallback_models', 4));

        return array_slice($out, 0, $max);
    }

    /** @param mixed $body */
    private function openRouterErrorShouldTryNextModel(int $status, $body): bool
    {
        if (in_array($status, [401, 403], true)) {
            return false;
        }

        if (in_array($status, [408, 429, 502, 503, 529], true)) {
            return true;
        }

        if ($status >= 500 && $status <= 599) {
            return true;
        }

        $msg = strtolower((string) ($this->extractOpenAiStyleError(is_array($body) ? $body : null) ?? ''));

        if ($status === 404) {
            return true;
        }

        if ($status === 402) {
            return true;
        }

        if ($status === 400) {
            return str_contains($msg, 'model')
                || str_contains($msg, 'not found')
                || str_contains($msg, 'no endpoints')
                || str_contains($msg, 'routing')
                || str_contains($msg, 'overload')
                || str_contains($msg, 'moderation')
                || str_contains($msg, 'unavailable')
                || str_contains($msg, 'disabled');
        }

        return false;
    }

    /** @param array{message: string, model?: string|null} $validated */
    private function chatOpenAiCompatible(string $provider, array $validated): JsonResponse
    {
        $row = AiProviderIntegration::where('name', $provider)->first();
        if (! $row) {
            return response()->json(['message' => 'Интеграция '.$provider.' не найдена в базе.'], 503);
        }

        $config = $row->config ?? [];
        $apiKey = trim((string) ($config['api_key'] ?? ''));
        if ($apiKey === '') {
            return response()->json([
                'message' => $provider === 'ollama'
                    ? 'Для Ollama не сохранён Token в CRM → Интеграции → ИИ.'
                    : 'Для '.$provider.' не сохранён API ключ в CRM → Интеграции → ИИ.',
            ], 503);
        }

        $model = $this->pickModel($provider, $validated, $config);
        $base = $this->openAiCompatibleBase($provider, $config);
        $url = $base.'/chat/completions';

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
        $headers['Authorization'] = 'Bearer '.$apiKey;

        $timeout = match ($provider) {
            'ollama' => (int) config('services.ollama.timeout', 120),
            default => (int) config('services.site_al.timeout', 120),
        };

        try {
            $response = Http::timeout($timeout)
                ->withHeaders($headers)
                ->post($url, [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'user', 'content' => self::RUSSIAN_OUTPUT_PREAMBLE.$validated['message']],
                    ],
                ]);
        } catch (\Throwable $e) {
            Log::warning('crm ai chat transport', ['provider' => $provider, 'error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Не удалось связаться с '.$provider.': '.$e->getMessage(),
            ], 502);
        }

        $body = $response->json();
        if (! $response->successful()) {
            $msg = $this->extractOpenAiStyleError($body) ?? ('Провайдер вернул HTTP '.$response->status());

            return response()->json([
                'message' => $msg,
                'details' => $body ?? $response->body(),
            ], $response->status() >= 400 && $response->status() < 600 ? $response->status() : 502);
        }

        if (! is_array($body)) {
            return response()->json(['message' => 'Неожиданный ответ провайдера (не JSON).'], 502);
        }

        $text = $body['choices'][0]['message']['content'] ?? null;
        if (! is_string($text) || $text === '') {
            return response()->json(['message' => 'Пустой ответ модели.'], 502);
        }

        $usage = $body['usage'] ?? null;
        $this->maybeLogUsage($provider, $model, is_array($usage) ? $usage : null, 'openai_style');

        return response()->json($this->withAiMeta([
            'reply' => $text,
            'conversationId' => null,
        ], $provider, $model));
    }

    /** @param array{message: string, model?: string|null} $validated */
    private function chatAnthropic(array $validated): JsonResponse
    {
        $provider = 'anthropic';
        $row = AiProviderIntegration::where('name', $provider)->first();
        if (! $row) {
            return response()->json(['message' => 'Интеграция anthropic не найдена в базе.'], 503);
        }

        $config = $row->config ?? [];
        $apiKey = trim((string) ($config['api_key'] ?? ''));
        if ($apiKey === '') {
            return response()->json([
                'message' => 'Для Anthropic не сохранён API-ключ в CRM → Интеграции → ИИ.',
            ], 503);
        }

        $model = $this->pickModel($provider, $validated, $config);

        try {
            $response = Http::timeout((int) config('services.site_al.timeout', 120))
                ->withHeaders([
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => $model,
                    'max_tokens' => 16384,
                    'messages' => [
                        ['role' => 'user', 'content' => self::RUSSIAN_OUTPUT_PREAMBLE.$validated['message']],
                    ],
                ]);
        } catch (\Throwable $e) {
            Log::warning('crm ai chat transport', ['provider' => $provider, 'error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Не удалось связаться с Anthropic: '.$e->getMessage(),
            ], 502);
        }

        $body = $response->json();
        if (! $response->successful()) {
            $msg = is_array($body) && isset($body['error']['message']) && is_string($body['error']['message'])
                ? $body['error']['message']
                : ('Anthropic вернул HTTP '.$response->status());

            return response()->json([
                'message' => $msg,
                'details' => $body ?? $response->body(),
            ], $response->status() >= 400 && $response->status() < 600 ? $response->status() : 502);
        }

        if (! is_array($body)) {
            return response()->json(['message' => 'Неожиданный ответ Anthropic (не JSON).'], 502);
        }

        $text = $this->anthropicTextFromContent($body['content'] ?? null);
        if ($text === '') {
            return response()->json(['message' => 'Пустой ответ модели Claude.'], 502);
        }

        $usage = $body['usage'] ?? null;
        $this->maybeLogAnthropicUsage($model, is_array($usage) ? $usage : null);

        return response()->json($this->withAiMeta([
            'reply' => $text,
            'conversationId' => null,
        ], 'anthropic', $model));
    }

    /** @param array{message: string, model?: string|null} $validated */
    private function chatGemini(array $validated): JsonResponse
    {
        $provider = 'gemini';
        $row = AiProviderIntegration::where('name', $provider)->first();
        if (! $row) {
            return response()->json(['message' => 'Интеграция gemini не найдена в базе.'], 503);
        }

        $config = $row->config ?? [];
        $apiKey = trim((string) ($config['api_key'] ?? ''));
        if ($apiKey === '') {
            return response()->json([
                'message' => 'Для Gemini не сохранён API-ключ в CRM → Интеграции → ИИ.',
            ], 503);
        }

        $model = $this->pickModel($provider, $validated, $config);
        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
            rawurlencode($model)
        );
        $url .= '?key='.rawurlencode($apiKey);

        try {
            $response = Http::timeout((int) config('services.site_al.timeout', 120))
                ->withHeaders([
                    'Content-Type' => 'application/json',
                ])
                ->post($url, [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => self::RUSSIAN_OUTPUT_PREAMBLE.$validated['message']],
                            ],
                        ],
                    ],
                ]);
        } catch (\Throwable $e) {
            Log::warning('crm ai chat transport', ['provider' => $provider, 'error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Не удалось связаться с Gemini: '.$e->getMessage(),
            ], 502);
        }

        $body = $response->json();
        if (! $response->successful()) {
            $msg = is_array($body) && isset($body['error']['message']) && is_string($body['error']['message'])
                ? $body['error']['message']
                : ('Gemini вернул HTTP '.$response->status());

            return response()->json([
                'message' => $msg,
                'details' => $body ?? $response->body(),
            ], $response->status() >= 400 && $response->status() < 600 ? $response->status() : 502);
        }

        if (! is_array($body)) {
            return response()->json(['message' => 'Неожиданный ответ Gemini (не JSON).'], 502);
        }

        $text = $this->geminiTextFromCandidates($body['candidates'] ?? null);
        if ($text === '') {
            return response()->json(['message' => 'Пустой ответ модели Gemini.'], 502);
        }

        $meta = $body['usageMetadata'] ?? null;
        $this->maybeLogGeminiUsage($model, is_array($meta) ? $meta : null);

        return response()->json($this->withAiMeta([
            'reply' => $text,
            'conversationId' => null,
        ], 'gemini', $model));
    }

    /**
     * @param  array{message: string, model?: string|null}  $validated
     * @param array<string, mixed> $config
     */
    private function pickModel(string $provider, array $validated, array $config): string
    {
        $req = isset($validated['model']) ? trim((string) $validated['model']) : '';
        if ($req !== '' && AiProviderCatalog::isValidModel($provider, $req)) {
            return $req;
        }

        $dm = trim((string) ($config['default_model'] ?? AiProviderCatalog::defaultModel($provider)));

        return AiProviderCatalog::isValidModel($provider, $dm)
            ? $dm
            : AiProviderCatalog::defaultModel($provider);
    }

    /** @param array<string, mixed> $config */
    private function openAiCompatibleBase(string $provider, array $config): string
    {
        $override = trim((string) ($config['base_url'] ?? ''));
        if ($override !== '') {
            return rtrim($override, '/');
        }

        return match ($provider) {
            'openai' => rtrim((string) config('services.openai.base_url', 'https://api.openai.com/v1'), '/'),
            'xai' => 'https://api.x.ai/v1',
            'ollama' => rtrim((string) config('services.ollama.base_url', 'http://127.0.0.1:11434'), '/'),
            'openrouter' => rtrim((string) config('services.openrouter.base_url', 'https://openrouter.ai/api/v1'), '/'),
            default => 'https://api.openai.com/v1',
        };
    }

    /** @param mixed $content */
    private function anthropicTextFromContent($content): string
    {
        if (! is_array($content)) {
            return '';
        }
        $parts = [];
        foreach ($content as $block) {
            if (! is_array($block)) {
                continue;
            }
            if (($block['type'] ?? '') === 'text' && isset($block['text']) && is_string($block['text'])) {
                $parts[] = $block['text'];
            }
        }

        return trim(implode("\n", $parts));
    }

    /** @param mixed $candidates */
    private function geminiTextFromCandidates($candidates): string
    {
        if (! is_array($candidates) || ! isset($candidates[0]) || ! is_array($candidates[0])) {
            return '';
        }
        $parts = $candidates[0]['content']['parts'] ?? null;
        if (! is_array($parts)) {
            return '';
        }
        $texts = [];
        foreach ($parts as $p) {
            if (is_array($p) && isset($p['text']) && is_string($p['text'])) {
                $texts[] = $p['text'];
            }
        }

        return trim(implode("\n", $texts));
    }

    /** @param mixed $body */
    private function extractOpenAiStyleError($body): ?string
    {
        if (! is_array($body)) {
            return null;
        }
        if (isset($body['error']['message']) && is_string($body['error']['message'])) {
            return $body['error']['message'];
        }
        if (isset($body['message']) && is_string($body['message'])) {
            return $body['message'];
        }

        return null;
    }

    /** @param array<string, mixed>|null $usage */
    private function maybeLogUsage(string $provider, string $model, ?array $usage, string $source): void
    {
        if ($usage === null) {
            return;
        }

        $pt = isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : null;
        $ct = isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : null;
        $tt = isset($usage['total_tokens']) ? (int) $usage['total_tokens'] : null;
        if ($tt === null && $pt !== null && $ct !== null) {
            $tt = $pt + $ct;
        }
        if ($tt === null && $pt === null && $ct === null) {
            return;
        }

        AiTokenUsageLog::query()->create([
            'provider' => $provider,
            'model' => $model,
            'prompt_tokens' => $pt,
            'completion_tokens' => $ct,
            'total_tokens' => $tt,
            'cost_usd' => null,
            'meta' => ['source' => $source],
        ]);
    }

    /** @param array<string, mixed>|null $usage */
    private function maybeLogAnthropicUsage(string $model, ?array $usage): void
    {
        if ($usage === null) {
            return;
        }

        $pt = isset($usage['input_tokens']) ? (int) $usage['input_tokens'] : null;
        $ct = isset($usage['output_tokens']) ? (int) $usage['output_tokens'] : null;
        $tt = ($pt !== null && $ct !== null) ? $pt + $ct : null;
        if ($tt === null && $pt === null && $ct === null) {
            return;
        }

        AiTokenUsageLog::query()->create([
            'provider' => 'anthropic',
            'model' => $model,
            'prompt_tokens' => $pt,
            'completion_tokens' => $ct,
            'total_tokens' => $tt,
            'cost_usd' => null,
            'meta' => ['source' => 'anthropic_messages'],
        ]);
    }

    /** @param array<string, mixed>|null $meta */
    private function maybeLogGeminiUsage(string $model, ?array $meta): void
    {
        if ($meta === null) {
            return;
        }

        $pt = isset($meta['promptTokenCount']) ? (int) $meta['promptTokenCount'] : null;
        $ct = isset($meta['candidatesTokenCount']) ? (int) $meta['candidatesTokenCount'] : null;
        $tt = isset($meta['totalTokenCount']) ? (int) $meta['totalTokenCount'] : null;
        if ($tt === null && $pt === null && $ct === null) {
            return;
        }

        AiTokenUsageLog::query()->create([
            'provider' => 'gemini',
            'model' => $model,
            'prompt_tokens' => $pt,
            'completion_tokens' => $ct,
            'total_tokens' => $tt,
            'cost_usd' => null,
            'meta' => ['source' => 'gemini_generateContent'],
        ]);
    }
}
