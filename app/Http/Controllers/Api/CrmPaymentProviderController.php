<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentProvider;
use App\Models\PaymentWebhookLog;
use App\Models\SaasApiKey;
use App\Support\FrontendUrl;
use App\Support\PaymentHttp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class CrmPaymentProviderController extends Controller
{
    private const TITLE_MAP = [
        'tinkoff' => 'Т-Банк',
        'sber' => 'Сбер',
        'atol' => 'АТОЛ (чеки)',
        'stripe' => 'Stripe',
    ];

    public function index(): JsonResponse
    {
        $providers = PaymentProvider::whereIn('name', ['tinkoff', 'sber', 'atol', 'stripe'])
            ->orderByRaw("FIELD(name, 'tinkoff', 'sber', 'atol', 'stripe')")
            ->get();

        $data = $providers->map(function (PaymentProvider $p) {
            $config = $this->maskConfig($p->config ?? []);
            $status = $this->isConnected($p) ? 'connected' : 'disconnected';
            return [
                'name' => $p->name,
                'title' => self::TITLE_MAP[$p->name] ?? $p->name,
                'is_active' => $p->is_active,
                'status' => $status,
                'config' => $config,
            ];
        });

        return response()->json($data);
    }

    public function update(Request $request, string $name): JsonResponse
    {
        $provider = PaymentProvider::where('name', $name)->firstOrFail();

        $allowed = $this->configFieldsForProvider($name);
        $rules = collect($allowed)->mapWithKeys(fn ($k) => [$k => 'nullable|string'])->all();
        $rules['is_active'] = 'nullable|boolean';
        $data = $request->validate($rules);

        if (array_key_exists('is_active', $data)) {
            $provider->update(['is_active' => (bool) $data['is_active']]);
        }

        $sensitive = ['password', 'pass', 'secret_key'];
        $config = $provider->config ?? [];
        foreach ($data as $key => $value) {
            if ($key === 'is_active' || !in_array($key, $allowed, true)) {
                continue;
            }
            if (in_array($key, $sensitive, true) && ($value === '***' || $value === '' || $value === null)) {
                continue;
            }
            $config[$key] = $value !== null && $value !== '' ? $value : null;
        }
        $provider->update(['config' => $config]);
        Cache::forget('payment_provider:' . $name);

        $provider->refresh();
        return response()->json([
            'name' => $provider->name,
            'is_active' => $provider->is_active,
            'config' => $this->maskConfig($provider->config ?? []),
            'status' => $this->isConnected($provider) ? 'connected' : 'disconnected',
        ]);
    }

    public function test(string $name): JsonResponse
    {
        $provider = PaymentProvider::where('name', $name)->firstOrFail();
        $config = $provider->config ?? [];

        try {
            $result = match ($name) {
                'tinkoff' => $this->testTinkoff($config),
                'sber' => $this->testSber($config),
                'atol' => $this->testAtol($config),
                default => ['success' => false, 'message' => 'Provider not supported for test'],
            };
            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function createTestPayment(string $name, Request $request): JsonResponse
    {
        if (!in_array($name, ['tinkoff', 'sber', 'atol'], true)) {
            return response()->json(['error' => 'Тестовый платёж только для tinkoff, sber, atol'], 400);
        }
        $provider = PaymentProvider::where('name', $name)->firstOrFail();
        $apiKeyId = (int) $request->input('api_key_id');
        $key = $apiKeyId > 0 ? SaasApiKey::find($apiKeyId) : SaasApiKey::where('is_active', true)->orderBy('id')->first();
        if (!$key) {
            return response()->json(['error' => 'Нет активного API-ключа'], 422);
        }

        $amount = 1.00; // 1 рубль — минимальный тестовый платёж
        $returnToken = \Illuminate\Support\Str::random(32);

        $paymentId = DB::table('payments')->insertGetId([
            'api_key_id' => $key->id,
            'amount' => $amount,
            'provider' => $name,
            'status' => 'succeeded',
            'provider_id' => 'test_' . time(),
            'provider_event_id' => 'test_' . time() . '_CONFIRMED',
            'return_token' => $returnToken,
            'user_email' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::transaction(function () use ($key, $amount, $paymentId, $name) {
            $key->balance = (float) $key->balance + $amount;
            $key->save();

            $payment = Payment::find($paymentId);
            if ($payment && !$payment->atol_uuid && $payment->atol_status !== 'processing') {
                $payment->update(['atol_status' => 'processing']);
                DB::afterCommit(fn () => \App\Jobs\SendAtolReceiptJob::dispatch($paymentId));
            }
        });

        PaymentWebhookLog::create([
            'provider' => $name,
            'provider_event_id' => 'test_' . $paymentId,
            'payload' => ['test' => true, 'payment_id' => $paymentId],
            'headers' => null,
            'status' => 'processed',
        ]);

        return response()->json([
            'message' => 'Тестовый платёж создан',
            'payment_id' => $paymentId,
            'amount' => $amount,
            'api_key_id' => $key->id,
            'new_balance' => (float) $key->fresh()->balance,
        ]);
    }

    public function paymentAlerts(): JsonResponse
    {
        $since = now()->subHours(24);
        $webhookFailures = PaymentWebhookLog::where('status', 'failed')
            ->where('created_at', '>=', $since)
            ->count();

        $atolFailures = Payment::where('atol_status', 'failed')
            ->where('updated_at', '>=', $since)
            ->count();

        $total = $webhookFailures + $atolFailures;
        $recent = PaymentWebhookLog::where('status', 'failed')
            ->where('created_at', '>=', $since)
            ->orderByDesc('id')
            ->limit(5)
            ->get(['id', 'provider', 'error', 'created_at']);

        return response()->json([
            'has_alerts' => $total > 0,
            'webhook_failures_24h' => $webhookFailures,
            'atol_failures_24h' => $atolFailures,
            'recent' => $recent->map(fn ($l) => [
                'id' => $l->id,
                'provider' => $l->provider,
                'error' => $l->error,
                'created_at' => $l->created_at?->toIso8601String(),
            ])->values()->all(),
        ]);
    }

    public function allLogs(Request $request): JsonResponse
    {
        $limit = min((int) $request->input('limit', 50), 100);
        $logs = PaymentWebhookLog::orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'provider', 'provider_event_id', 'status', 'error', 'created_at'])
            ->map(fn ($l) => [
                'id' => $l->id,
                'provider' => $l->provider,
                'event_id' => $l->provider_event_id,
                'status' => $l->status,
                'error' => $l->error,
                'created_at' => $l->created_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $logs]);
    }

    public function logs(string $name, Request $request): JsonResponse
    {
        $limit = min((int) $request->input('limit', 20), 50);
        $logs = PaymentWebhookLog::where('provider', $name)
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'provider_event_id', 'status', 'error', 'created_at'])
            ->map(fn ($l) => [
                'id' => $l->id,
                'event_id' => $l->provider_event_id,
                'status' => $l->status,
                'error' => $l->error,
                'created_at' => $l->created_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $logs]);
    }

    private function testTinkoff(array $config): array
    {
        if (empty($config['terminal_key']) || empty($config['password'])) {
            return ['success' => false, 'message' => 'Terminal Key и Password обязательны'];
        }

        try {
            $provider = new \App\Services\Payments\TinkoffProvider($config);
            $provider->createCheckout(null, 1.0, [
                'payment_id' => (int) time(),
                'description' => 'Connection test',
                'line_item_name' => 'Connection test',
            ]);

            return ['success' => true, 'message' => 'Подключение успешно'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function testSber(array $config): array
    {
        if (empty($config['merchant_login']) || empty($config['password'])) {
            return ['success' => false, 'message' => 'userName и password обязательны'];
        }
        $sberBase = ($config['mode'] ?? 'prod') === 'test'
            ? 'https://3dsec.sberbank.ru'
            : 'https://securepayments.sberbank.ru';
        $frontendUrl = FrontendUrl::base();
        $formData = [
            'userName' => $config['merchant_login'],
            'password' => $config['password'],
            'orderNumber' => 'test_' . time(),
            'amount' => 100,
            'returnUrl' => $frontendUrl . '/payment/success',
            'failUrl' => $frontendUrl . '/payment/fail',
            'description' => 'Connection test',
        ];
        $response = PaymentHttp::client(10)->asForm()->post($sberBase . '/payment/rest/register.do', $formData);
        $body = $response->json() ?? [];
        $code = (int) ($body['errorCode'] ?? -1);
        if ($code === 0 && !empty($body['orderId'])) {
            return ['success' => true, 'message' => 'Подключение успешно'];
        }
        return ['success' => false, 'message' => $body['errorMessage'] ?? 'Ошибка Sber API'];
    }

    private function testAtol(array $config): array
    {
        if (empty($config['login']) || empty($config['password'])) {
            return ['success' => false, 'message' => 'login и password обязательны'];
        }
        $base = ($config['mode'] ?? 'prod') === 'test'
            ? 'https://online.atol.ru/possystem/v4'
            : 'https://online.atol.ru/possystem/v4';
        $response = Http::asJson()->timeout(10)->post($base . '/getToken', [
            'login' => $config['login'],
            'pass' => $config['password'],
        ]);
        $body = $response->json() ?? [];
        if (!empty($body['token'])) {
            return ['success' => true, 'message' => 'Подключение успешно'];
        }
        return ['success' => false, 'message' => $body['error'] ?? $body['text'] ?? 'Ошибка ATOL API'];
    }

    private function isConnected(PaymentProvider $p): bool
    {
        $config = $p->config ?? [];
        return match ($p->name) {
            'tinkoff' => !empty($config['terminal_key']) && !empty($config['password']),
            'sber' => !empty($config['merchant_login']) && !empty($config['password']),
            'atol' => !empty($config['login']) && !empty($config['password']) && !empty($config['group_code']),
            'stripe' => !empty($config['secret_key'] ?? $config['publishable_key'] ?? null),
            default => false,
        };
    }

    private function maskConfig(array $config): array
    {
        $sensitive = ['password', 'pass', 'secret_key', 'terminal_key', 'merchant_login'];
        $out = [];
        foreach ($config as $k => $v) {
            if (in_array($k, $sensitive, true) && is_string($v) && strlen($v) > 0) {
                $out[$k] = '***';
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    public function show(string $name): JsonResponse
    {
        $provider = PaymentProvider::where('name', $name)->firstOrFail();
        $baseUrl = rtrim(config('app.url', ''), '/');
        $notificationUrl = $baseUrl . '/api/v1/webhook/' . $name;

        return response()->json([
            'name' => $provider->name,
            'title' => self::TITLE_MAP[$provider->name] ?? $provider->name,
            'is_active' => $provider->is_active,
            'status' => $this->isConnected($provider) ? 'connected' : 'disconnected',
            'config' => $this->maskConfig($provider->config ?? []),
            'notification_url' => $notificationUrl,
            'config_schema' => $this->configSchemaForProvider($name),
        ]);
    }

    private function configSchemaForProvider(string $name): array
    {
        return match ($name) {
            'tinkoff' => [
                ['key' => 'terminal_key', 'label' => 'Terminal Key', 'type' => 'text', 'required' => true],
                ['key' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => true],
                ['key' => 'notification_url', 'label' => 'Notification URL', 'type' => 'text', 'readonly' => true],
                ['key' => 'mode', 'label' => 'Режим', 'type' => 'select', 'options' => [['value' => 'test', 'label' => 'Тест'], ['value' => 'prod', 'label' => 'Продакшен']]],
            ],
            'sber' => [
                ['key' => 'merchant_login', 'label' => 'userName', 'type' => 'text', 'required' => true],
                ['key' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => true],
                ['key' => 'mode', 'label' => 'Режим', 'type' => 'select', 'options' => [['value' => 'test', 'label' => 'Тест'], ['value' => 'prod', 'label' => 'Продакшен']]],
            ],
            'atol' => [
                ['key' => 'login', 'label' => 'Login', 'type' => 'text', 'required' => true],
                ['key' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => true],
                ['key' => 'group_code', 'label' => 'Group Code', 'type' => 'text', 'required' => true],
                ['key' => 'tax', 'label' => 'Фискализация (tax)', 'type' => 'select', 'options' => [
                    ['value' => 'none', 'label' => 'Без НДС'], ['value' => 'vat0', 'label' => 'НДС 0%'],
                    ['value' => 'vat10', 'label' => 'НДС 10%'], ['value' => 'vat20', 'label' => 'НДС 20%'],
                ]],
                ['key' => 'payment_method', 'label' => 'Способ оплаты', 'type' => 'text'],
                ['key' => 'payment_object', 'label' => 'Предмет расчёта', 'type' => 'text'],
                ['key' => 'email', 'label' => 'Email по умолчанию', 'type' => 'text'],
                ['key' => 'mode', 'label' => 'Режим', 'type' => 'select', 'options' => [['value' => 'test', 'label' => 'Тест'], ['value' => 'prod', 'label' => 'Продакшен']]],
            ],
            default => [],
        };
    }

    private function configFieldsForProvider(string $name): array
    {
        $schema = $this->configSchemaForProvider($name);
        return array_values(array_filter(
            array_map(fn ($f) => ($f['readonly'] ?? false) ? null : $f['key'], $schema)
        ));
    }
}
