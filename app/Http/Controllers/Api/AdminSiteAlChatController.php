<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Прокси к внешнему агенту site-al (ключ только на сервере).
 *
 * POST /api/v1/admin/site-al/chat
 */
class AdminSiteAlChatController extends Controller
{
    /**
     * Жёсткое требование языка для CRM-витрины (снижает «ответы» модели на китайском/английских отказах).
     */
    private const RUSSIAN_OUTPUT_PREAMBLE = <<<'TXT'
КРИТИЧЕСКОЕ ТРЕБОВАНИЕ К ИТОГОВОМУ ОТВЕТУ:
— Пиши только по-русски: основной текст — кириллица, как на витрине маркетплейса в России.
— Латиница допустима только в уместных местах (маркировки размеров S/M/L, бренды из исходных данных, единицы измерения, если они уже в материале). Не добавляй новые английские предложения и служебные фразы.
— Запрещено: китайские, японские, корейские и прочие символы; фразы вроде «please rephrase», просьбы перейти на английский, «将需求», вставки вроде «冷水，请您…».
— Не отказывайся и не проси пользователя переформулировать запрос — просто выполни задачу по-русски.

Ниже — фактическое задание от оператора CRM:

TXT;

    public function chat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:50000'],
            'conversationId' => ['nullable', 'string', 'max:256'],
            'agentId' => ['nullable', 'uuid'],
            'model' => ['nullable', 'string', 'max:128'],
        ]);

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

        return response()->json([
            'reply' => is_string($reply) ? $reply : (is_scalar($reply) ? (string) $reply : null),
            'conversationId' => is_string($conversationId) ? $conversationId : null,
        ]);
    }
}
